<?php

namespace App\Tests\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Seed (docs/teststack.md): testuser 3 owns alpha 1 and gamma 3 (Tech-C testowner2 4); testowner2 owns beta 2
 * (Tech-C testtech 5); testtech owns delta 4, the only node without devices. Proposal -1 invites testplain 6 as
 * Tech-C of alpha; -2 hands beta to testhonor 7, testowner2 stays Tech-C. testfriend 8 is not involved anywhere.
 */
abstract class NodeTestCase extends HttpStackTestCase
{
    protected const NODES = '/?section=nodes';

    protected static function url(string $action, int|string $id): string
    {
        return self::NODES.'&action='.$action.'&id='.$id;
    }

    /** @return array{int, int}|null owner and Tech-C of a node, or id_members and id_tech_c of a proposal row */
    protected function row(int $id): ?array
    {
        $row = $this->db()->fetchNumeric('SELECT id_members, id_tech_c FROM nodes WHERE id = ?', [$id]);

        return false === $row ? null : [(int) $row[0], (int) $row[1]];
    }

    /** @return list<array<string, mixed>> the whole table, to prove that a request wrote nothing */
    protected function nodes(): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM nodes ORDER BY id');
    }

    /** @return array<string, string> the hidden fields of the confirmation form the member is shown */
    protected function confirmation(Browser $browser, string $action, int $id): array
    {
        $html = $browser->get(self::url($action, $id))->getContent();
        $fields = ['_csrf_token' => Browser::token($html, 'node-'.$action)];
        if (1 === preg_match('/name="proposal" value="([^"]+)"/', $html, $match)) {
            $fields['proposal'] = $match[1];
        }

        return $fields;
    }

    /** Opens the confirmation page and submits it, as a member does. */
    protected function confirm(Browser $browser, string $action, int $id): ResponseInterface
    {
        return $browser->post(self::url($action, $id), $this->confirmation($browser, $action, $id));
    }

    /** @param array<string, string> $fields */
    protected function propose(Browser $browser, string $action, int $id, array $fields): ResponseInterface
    {
        return $browser->post(self::url($action, $id), $fields + ['_csrf_token' => $browser->tokenFrom(self::url($action, $id), 'node-propose')]);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, string> a valid node form
     */
    protected static function nodeForm(array $fields = []): array
    {
        return $fields + [
            'name' => 'probe7', 'gps_lat_deg' => '48', 'gps_lat_min' => '12', 'gps_lat_sec' => '30,5',
            'gps_lon_deg' => '16', 'gps_lon_min' => '21', 'gps_lon_sec' => '0', 'map' => 'on',
        ];
    }

    /** @param array<string, string> $fields */
    protected function createNode(Browser $browser, array $fields = []): ResponseInterface
    {
        return $browser->post(self::NODES.'&action=insert', self::nodeForm($fields) + ['_csrf_token' => $browser->tokenFrom(self::NODES.'&action=insert', 'node-insert')]);
    }

    protected function nodeId(string $name): int
    {
        return (int) $this->db()->fetchOne('SELECT id FROM nodes WHERE name = ?', [$name]);
    }

    /** The part of the overview that belongs to one table. */
    protected static function section(string $html, string $name): string
    {
        return 1 === preg_match('/<table data-section="'.preg_quote($name, '/').'">.*?<\/table>/s', $html, $match) ? $match[0] : '';
    }

    /** One table row of the overview. */
    protected static function nodeRow(string $html, string $section, int $id): string
    {
        return 1 === preg_match('/<tr data-node-id="'.$id.'">.*?<\/tr>/s', self::section($html, $section), $match) ? $match[0] : '';
    }
}
