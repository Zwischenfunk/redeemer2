<?php

namespace App\Node;

/** The rules of the legacy node form (index.php, js/main.js), enforced on the server. */
final class NodeRules
{
    /** Upper and lower case as in the legacy check. Any such label keeps "<device>.<name>.wien.funkfeuer.at" a valid domain name. */
    private const NAME = '/^[a-z0-9]([a-z0-9-]{0,23}[a-z0-9])?$/i';
    private const NUMBER = '/^[0-9]{1,3}([.,][0-9]{1,9})?$/';
    private const MAXIMUM = [
        'gps_lat_deg' => 90, 'gps_lat_min' => 59, 'gps_lat_sec' => 59.999,
        'gps_lon_deg' => 180, 'gps_lon_min' => 59, 'gps_lon_sec' => 59.999,
    ];

    /** A posted checkbox; the values the legacy forms and routines read as true. */
    public static function checked(mixed $value): bool
    {
        return in_array($value, ['on', 'true', 't', '1'], true);
    }

    /**
     * Every coordinate field is optional, as in the legacy form. Unknown fields are ignored.
     *
     * @param array<string, mixed> $input
     *
     * @return array{name: string, map: bool, coordinates: array<string, float|null>}
     *
     * @throws Rejected
     */
    public function validated(array $input): array
    {
        $errors = [];
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if (1 !== preg_match(self::NAME, $name)) {
            $errors['name'] = 'Erlaubt sind a-z, 0-9 und Bindestrich (nicht am Anfang oder Ende), höchstens 25 Zeichen.';
        }

        $coordinates = [];
        foreach (self::MAXIMUM as $field => $maximum) {
            $raw = $input[$field] ?? '';
            $raw = is_string($raw) ? trim($raw) : null;
            $coordinates[$field] = null;
            if ('' === $raw) {
                continue;
            }
            if (null === $raw || 1 !== preg_match(self::NUMBER, $raw) || (float) str_replace(',', '.', $raw) > $maximum) {
                $errors[$field] = sprintf('Zahl zwischen 0 und %s erwartet.', $maximum);
                continue;
            }
            $coordinates[$field] = (float) str_replace(',', '.', $raw);
        }

        if ([] !== $errors) {
            throw Rejected::fields($errors);
        }

        return ['name' => $name, 'map' => self::checked($input['map'] ?? null), 'coordinates' => $coordinates];
    }
}
