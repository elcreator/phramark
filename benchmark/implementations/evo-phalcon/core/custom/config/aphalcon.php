<?php

use Phalcon\Db\Enum;
use Phalcon\Di\DiInterface;

return [
    'frontend' => ['takeover' => true, 'fallback' => false],
    'latte' => ['function' => 'phalcon'],
    'providers' => [
        static function (DiInterface $di): void {
            $di->setShared('benchmarkCategory', function () use ($di): object {
                return new class($di->getShared('db'), (string) $di->getShared('tablePrefix')) {
                    public function __construct(private object $db, private string $prefix) {}

                    /** @return array{category: array<string, mixed>, cards: list<array<string, mixed>>} */
                    public function forDocument(int $id): array
                    {
                        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $this->prefix);
                        $content = '`' . $prefix . "site_content`";
                        $tvs = '`' . $prefix . "site_tmplvars`";
                        $values = '`' . $prefix . "site_tmplvar_contentvalues`";
                        $category = $this->db->fetchOne("SELECT id, pagetitle, description, introtext, alias FROM {$content} WHERE id = :id", Enum::FETCH_ASSOC, ['id' => $id]);
                        $cards = $this->db->fetchAll("SELECT id, pagetitle, introtext, alias, pub_date FROM {$content} WHERE parent = :parent AND published = 1 AND deleted = 0 ORDER BY menuindex ASC LIMIT 20", Enum::FETCH_ASSOC, ['parent' => $id]);
                        if ($cards === []) {
                            return ['category' => $category ?: [], 'cards' => []];
                        }
                        $ids = array_map(static fn (array $card): int => (int) $card['id'], $cards);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $tvRows = $this->db->fetchAll("SELECT value.contentid, tv.name, value.value FROM {$values} value INNER JOIN {$tvs} tv ON tv.id = value.tmplvarid WHERE value.contentid IN ({$placeholders})", Enum::FETCH_ASSOC, $ids);
                        $byContent = [];
                        foreach ($tvRows as $tv) {
                            $byContent[(int) $tv['contentid']][(string) $tv['name']] = $tv['value'];
                        }
                        foreach ($cards as &$card) {
                            $card += $byContent[(int) $card['id']] ?? [];
                        }
                        unset($card);

                        return ['category' => $category ?: [], 'cards' => $cards];
                    }
                };
            });
        },
    ],
];
