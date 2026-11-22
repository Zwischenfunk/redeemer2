<?php

namespace App\Member;

use Psr\Log\LoggerInterface;

/**
 * Member lifecycle across LDAP, database and mail. Nothing is touched before validation and permission
 * checks pass. After that LDAP comes first, then the database; effects that already happened when a later
 * step fails are reported as Status::Partial, never retried or rolled back silently.
 */
final readonly class MemberAccounts
{
    private const PASSWORD_ALPHABET = '0123456789bcdfghjkmnpqrstvwxyzABCDEFGHJKLMNPQRSTUVWXYZ@:-()!$%';

    public function __construct(
        private MemberDirectory $directory,
        private MemberStore $store,
        private AccountMailer $mailer,
        private MemberQueries $queries,
        private MemberRules $rules,
        private MemberPolicy $policy,
        private RoleDelegation $roles,
        private LoggerInterface $logger,
        private bool $welcomeMail,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(Actor $actor, array $input): Outcome
    {
        if (!$this->policy->mayCreate($actor)) {
            throw new Denied();
        }
        $permission = $this->policy->insertPermission();
        $this->assertOnlyKnown($input, $permission->fields);
        [$values, $errors] = $this->rules->validate($permission->fields, $input);
        $values['nickname'] = mb_strtolower($values['nickname']);

        $password = $input['password'] ?? '';
        if (!is_string($password)) {
            $errors['password'] = 'Ungültige Eingabe.';
        } elseif ('' !== $password && null !== ($error = $this->rules->passwordError($password, $password))) {
            $errors['password'] = $error;
        }
        if ([] !== $errors) {
            return Outcome::rejected('Bitte die markierten Felder korrigieren.', $errors);
        }

        try {
            if ($this->queries->nicknameTaken($values['nickname']) || $this->directory->nicknameExists($values['nickname'])) {
                return Outcome::rejected('Bitte die markierten Felder korrigieren.', ['nickname' => 'Dieser Nickname ist bereits vergeben.']);
            }
        } catch (BackendFailure $failure) {
            return $this->failed($failure);
        }

        $generated = '' === $password;
        $password = $generated ? $this->generatePassword() : $password;
        $profile = Profile::fromArray($values);

        try {
            $this->directory->add($profile->nickname, $profile, $password);
        } catch (BackendFailure $failure) {
            return $this->failed($failure, 'Es wurde nichts angelegt.');
        }

        try {
            $id = $this->store->insert($profile->nickname, $profile);
        } catch (BackendFailure $failure) {
            $this->log($failure);

            return Outcome::partial([
                $failure->getMessage(),
                sprintf('Das LDAP-Konto "%s" wurde bereits angelegt, hat aber noch keine Mitglieds-ID. Bitte einen Administrator informieren.', $profile->nickname),
            ]);
        }

        try {
            $this->directory->assignId($profile->nickname, $id);
        } catch (BackendFailure $failure) {
            $this->log($failure);

            return Outcome::partial([
                $failure->getMessage(),
                sprintf('Das Mitglied "%s" (ID %d) existiert in der Datenbank, das LDAP-Konto hat aber noch keine ID. Es wurde keine E-Mail versendet.', $profile->nickname, $id),
            ], $id);
        }

        $messages = [sprintf('Das Mitglied "%s" (ID %d) wurde angelegt.', $profile->nickname, $id)];
        if ($this->welcomeMail) {
            try {
                $this->mailer->sendWelcome($profile->email, $profile->nickname, $password);
                $messages[] = 'Die Zugangsdaten wurden per E-Mail versendet.';
            } catch (BackendFailure $failure) {
                $this->log($failure);

                return Outcome::partial([
                    ...$messages,
                    'Die E-Mail mit den Zugangsdaten konnte nicht versendet werden. Das Passwort kann über "Passwort vergessen" zurückgesetzt werden.',
                ], $id);
            }
        }

        return Outcome::done($messages, $id);
    }

    /** @param array<string, mixed> $input */
    public function update(Actor $actor, int $memberId, array $input): Outcome
    {
        $permission = $this->policy->forExisting($actor, $memberId) ?? throw new Denied();
        $current = $this->queries->profile($memberId) ?? throw new NotFound();
        $groups = $this->roles->groupsFor($actor, $memberId);

        $this->assertOnlyKnown($input, $permission->fields, ['password', 'password2', 'roles']);
        $posted = $input['roles'] ?? [];
        if (!is_array($posted) || [] !== array_diff(array_keys($posted), array_keys($groups))) {
            throw new Denied();
        }
        $password = $input['password'] ?? '';
        $confirmation = $input['password2'] ?? '';
        if ((('' !== $password || '' !== $confirmation) && !$permission->password) || !is_string($password) || !is_string($confirmation)) {
            throw new Denied();
        }

        [$values, $errors] = $this->rules->validate($permission->fields, $input);
        if ('' !== $password || '' !== $confirmation) {
            if (null !== ($error = $this->rules->passwordError($password, $confirmation))) {
                $errors['password'] = $error;
            }
        }
        $roleChanges = [];
        foreach ($posted as $key => $selected) {
            $ids = $groups[$key]['ids'];
            if (!is_string($selected) || 1 !== preg_match('/^[0-9]{1,9}$/', $selected) || (0 !== (int) $selected && !in_array((int) $selected, $ids, true))) {
                $errors['roles'] = 'Ungültige Rollenauswahl.';
                continue;
            }
            $roleChanges[] = ['ids' => $ids, 'selected' => (int) $selected];
        }
        if ([] !== $errors) {
            return Outcome::rejected('Bitte die markierten Felder korrigieren.', $errors);
        }

        $profile = $current->with($values);
        $attributes = [];
        foreach (['givenName' => 'firstname', 'sn' => 'lastname', 'mail' => 'email'] as $attribute => $field) {
            if ($profile->$field !== $current->$field) {
                $attributes[$attribute] = $profile->$field;
            }
        }
        if ('' !== $password) {
            $attributes['userPassword'] = $password;
        }

        $directoryChanged = false;
        if ([] !== $attributes) {
            try {
                $this->directory->update($memberId, $attributes);
                $directoryChanged = true;
            } catch (BackendFailure $failure) {
                return $this->failed($failure, 'Es wurde nichts geändert.');
            }
        }

        try {
            $this->store->update($memberId, $profile, $roleChanges);
        } catch (BackendFailure $failure) {
            if (!$directoryChanged) {
                return $this->failed($failure, 'Es wurde nichts geändert.');
            }
            $this->log($failure);

            return Outcome::partial([
                $failure->getMessage(),
                'Die Änderungen im LDAP-Verzeichnis (Name, E-Mail oder Passwort) wurden bereits gespeichert, die Datenbank nicht. Bitte erneut speichern oder einen Administrator informieren.',
            ]);
        }

        return Outcome::done(['Die Daten wurden gespeichert.']);
    }

    public function delete(Actor $actor, int $memberId): Outcome
    {
        if (!$this->policy->mayDelete($actor)) {
            throw new Denied();
        }
        if (null === $this->queries->profile($memberId)) {
            throw new NotFound();
        }
        if ($memberId === $actor->originalId || $memberId === $actor->effectiveId) {
            return Outcome::rejected('Fehler - das eigene Konto bzw. das Konto des aktuellen Identitätswechsels kann nicht gelöscht werden.');
        }
        if ([] !== ($blockers = $this->queries->deleteBlockers($memberId))) {
            return Outcome::rejected($blockers[0]);
        }

        try {
            // A missing account is the state after a partial delete (or a SQL-only member): finish the database side.
            if ($this->directory->exists($memberId)) {
                $this->directory->remove($memberId);
            }
        } catch (BackendFailure $failure) {
            return $this->failed($failure, 'Das Mitglied wurde nicht gelöscht.');
        }

        try {
            $this->store->delete($memberId);
        } catch (BackendFailure $failure) {
            $this->log($failure);

            return Outcome::partial([
                $failure->getMessage(),
                'Das LDAP-Konto wurde bereits gelöscht, der Datenbank-Eintrag besteht noch. Bitte einen Administrator informieren.',
            ]);
        }

        return Outcome::done(['Das Mitglied wurde gelöscht.']);
    }

    public function resetPassword(string $nickname, string $email): Outcome
    {
        $unknown = 'Das Passwort konnte nicht zurückgesetzt werden: Es gibt kein eindeutig passendes Mitglied mit einer gültigen E-Mail-Adresse.';
        $nickname = trim($nickname);
        $email = trim($email);
        if (('' === $nickname && '' === $email) || 1 === preg_match('/[\x00-\x1f\x7f]/', $nickname.$email) || strlen($nickname.$email) > 100) {
            return Outcome::rejected($unknown);
        }
        $matches = $this->queries->loginMatches($nickname, $email);
        if (1 !== count($matches) || !$this->rules->isMailAddress($matches[0]['email']) || '' === $matches[0]['email']) {
            return Outcome::rejected($unknown);
        }
        ['id' => $id, 'nickname' => $member, 'email' => $to] = $matches[0];

        $password = $this->generatePassword();
        try {
            if (!$this->directory->exists($id)) {
                return Outcome::rejected($unknown);
            }
            $this->directory->update($id, ['userPassword' => $password]);
        } catch (BackendFailure $failure) {
            return $this->failed($failure, 'Das Passwort wurde nicht geändert.');
        }

        try {
            $this->mailer->sendReset($to, $member, $password);
        } catch (BackendFailure $failure) {
            $this->log($failure);

            return Outcome::partial(['Das Passwort wurde geändert, die E-Mail mit dem neuen Passwort konnte aber nicht versendet werden. Bitte einen Administrator kontaktieren.']);
        }

        return Outcome::done(['Das Passwort wurde zurückgesetzt. Bitte E-Mails prüfen: Das neue Passwort wurde an die hinterlegte Adresse gesendet.']);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $fields
     * @param list<string>         $meta
     */
    private function assertOnlyKnown(array $input, array $fields, array $meta = ['password']): void
    {
        $allowed = [...$fields, ...$meta, '_csrf_token', 'mini'];
        if ([] !== array_diff(array_keys($input), $allowed)) {
            throw new Denied();
        }
    }

    private function generatePassword(): string
    {
        $password = '';
        for ($i = 0; $i < 8; ++$i) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)];
        }

        return $password;
    }

    private function failed(BackendFailure $failure, string $consequence = ''): Outcome
    {
        $this->log($failure);

        return Outcome::failed(trim($failure->getMessage().' '.$consequence));
    }

    private function log(BackendFailure $failure): void
    {
        $this->logger->error('Member backend failure: {message}', ['message' => $failure->getMessage(), 'cause' => $failure->getPrevious()?->getMessage()]);
    }
}
