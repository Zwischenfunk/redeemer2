<?php

namespace App\Member;

use Symfony\Component\Mime\Address;

/** The validation rules of the legacy forms (js/main.js); the server enforces them, too. */
final class MemberRules
{
    private const PATTERNS = [
        'nickname' => ['/^[a-z0-9]{1,25}$/i', 25, 'Nur Buchstaben a-z und Ziffern, 1 bis 25 Zeichen.'],
        'firstname' => ['/^[a-zäöüß]{1,25}$/iu', 50, 'Nur Buchstaben, 1 bis 25 Zeichen.'],
        'lastname' => ['/^[a-zäöüß]{1,25}$/iu', 50, 'Nur Buchstaben, 1 bis 25 Zeichen.'],
        'street' => ['/^.+$/u', 100, 'Straße fehlt.'],
        'housenumber' => ['/^([0-9]{1,3}[a-z\-]{0,1}[0-9]{0,3}[0-9 \/.\-]{0,9}[a-z0-9\/]{0,7})$/i', 20, 'Hausnummer ungültig.'],
        'zip' => ['/^([A-Z\-]{0,3}[0-9A-Z\ ]{4,7})$/', 10, 'Postleitzahl ungültig.'],
        'town' => ['/^.+$/u', 100, 'Ort fehlt.'],
        'telephone' => ['/^([0-9 +\/-]{1,25})?$/', 25, 'Telefonnummer ungültig.'],
        'mobilephone' => ['/^([0-9 +\/-]{1,25})?$/', 25, 'Telefonnummer ungültig.'],
        'fax' => ['/^([0-9 +\/-]{1,25})?$/', 25, 'Faxnummer ungültig.'],
        'homepage' => ['~^(https?://.+)?$~', 50, 'Homepage muss mit http:// oder https:// beginnen.'],
    ];
    private const EMAIL = '/^(([^<>()[\]\\\\.,;:\s@"]+(\.[^<>()[\]\\\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/';

    /**
     * Validates exactly the given fields. Values are trimmed.
     *
     * @param list<string>         $fields
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, string>, 1: array<string, string>} values and errors by field
     */
    public function validate(array $fields, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $field) {
            $raw = $input[$field] ?? '';
            if (!is_string($raw)) {
                $errors[$field] = 'Ungültige Eingabe.';
                continue;
            }
            $value = trim($raw);
            $values[$field] = $value;
            if (1 === preg_match('/[\x00-\x1f\x7f]/', $value) || !mb_check_encoding($value, 'UTF-8')) {
                $errors[$field] = 'Steuerzeichen sind nicht erlaubt.';
            } elseif ('email' === $field) {
                if (mb_strlen($value) > 50 || 1 !== preg_match(self::EMAIL, $value) || !$this->isMailAddress($value)) {
                    $errors[$field] = 'E-Mail-Adresse ungültig.';
                }
            } else {
                [$pattern, $max, $message] = self::PATTERNS[$field];
                if (mb_strlen($value) > $max || 1 !== preg_match($pattern, $value)) {
                    $errors[$field] = $message;
                }
            }
        }

        return [$values, $errors];
    }

    public function isMailAddress(string $value): bool
    {
        try {
            new Address($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return string|null an error message, or null if the new password is acceptable */
    public function passwordError(string $password, string $confirmation): ?string
    {
        return match (true) {
            $password !== $confirmation => 'Die Passwörter stimmen nicht überein.',
            1 === preg_match('/[\x00-\x1f\x7f]/', $password) || mb_strlen($password) > 128 => 'Das Passwort enthält unzulässige Zeichen oder ist zu lang.',
            default => null,
        };
    }
}
