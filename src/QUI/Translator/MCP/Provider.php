<?php

namespace QUI\Translator\MCP;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI;
use QUI\AI\MCP\ProviderInterface;
use QUI\AI\MCP\Server;
use QUI\AI\MCP\ToolHelper;
use QUI\Permissions\Permission;
use Throwable;

class Provider implements ProviderInterface
{
    protected const BASE_FIELDS = [
        'id',
        'groups',
        'var',
        'package',
        'datatype',
        'datadefine',
        'html',
        'priority'
    ];

    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                int | null $limit = null,   
                int | null $page = null,
                array | null $languages = null,
                string | null $group = null
            ): CallToolResult | array {
                try {
                    self::checkPermission();

                    return self::getLanguageVariables($limit, $page, $languages, $group);
                } catch (Throwable $e) {
                    return ToolHelper::parseExceptionToResult($e);
                }
            },
            name: 'translator_language_variables_get',
            description: 'Returns translator language variables with pagination and optional language filtering.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of variables to return.',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 500
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Result page, starting at 1.',
                        'default' => 1,
                        'minimum' => 1
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'Language columns to include, for example ["de", "en"].',
                        'items' => [
                            'type' => 'string'
                        ]
                    ],
                    'group' => [
                        'type' => 'string',
                        'description' => 'Optional translation group filter.'
                    ]
                ]
            ]
        );

        $serverBuilder->addTool(
            function (array $variables, bool | null $publish = null): CallToolResult | array {
                try {
                    self::checkPermission();

                    return self::saveLanguageVariables($variables, !empty($publish));
                } catch (Throwable $e) {
                    return ToolHelper::parseExceptionToResult($e);
                }
            },
            name: 'translator_language_variables_save',
            description: 'Creates or updates translator language variables.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['variables'],
                'properties' => [
                    'variables' => [
                        'type' => 'array',
                        'description' => 'Variables to create or update.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['group', 'var', 'translations'],
                            'properties' => [
                                'group' => [
                                    'type' => 'string',
                                    'description' => 'Translation group.'
                                ],
                                'var' => [
                                    'type' => 'string',
                                    'description' => 'Translation variable name.'
                                ],
                                'package' => [
                                    'type' => 'string',
                                    'description' => 'Package name. Defaults to an empty package.'
                                ],
                                'datatype' => [
                                    'type' => 'string',
                                    'description' => 'Translation datatype, for example php,js.',
                                    'default' => 'php,js'
                                ],
                                'html' => [
                                    'type' => 'boolean',
                                    'description' => 'Whether the translation contains HTML.',
                                    'default' => false
                                ],
                                'priority' => [
                                    'type' => 'integer',
                                    'description' => 'Translation priority.',
                                    'default' => 0
                                ],
                                'translations' => [
                                    'type' => 'object',
                                    'description' => 'Language values keyed by language code.'
                                ]
                            ]
                        ]
                    ],
                    'publish' => [
                        'type' => 'boolean',
                        'description' => 'Publish affected groups after saving.',
                        'default' => false
                    ]
                ]
            ]
        );

        $serverBuilder->addTool(
            function (
                string $search,
                int | null $limit = null,
                int | null $page = null,
                array | null $languages = null,
                array | null $fields = null
            ): CallToolResult | array {
                try {
                    self::checkPermission();

                    return self::searchLanguageVariables($search, $limit, $page, $languages, $fields);
                } catch (Throwable $e) {
                    return ToolHelper::parseExceptionToResult($e);
                }
            },
            name: 'translator_language_variables_search',
            description: 'Searches translator language variables and returns matching entries.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['search'],
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term.'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of matches to return.',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 500
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Result page, starting at 1.',
                        'default' => 1,
                        'minimum' => 1
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'Language columns to include, for example ["de", "en"].',
                        'items' => [
                            'type' => 'string'
                        ]
                    ],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Fields to search. Defaults to metadata and all language fields.',
                        'items' => [
                            'type' => 'string'
                        ]
                    ]
                ]
            ]
        );

        $serverBuilder->addTool(
            function (array | null $groups = null, bool | null $all = null): CallToolResult | array {
                try {
                    self::checkPermission();

                    return self::publishLanguageVariables($groups, !empty($all));
                } catch (Throwable $e) {
                    return ToolHelper::parseExceptionToResult($e);
                }
            },
            name: 'translator_language_variables_publish',
            description: 'Publishes translator language variable groups. Publishes all groups when no groups are provided.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'groups' => [
                        'type' => 'array',
                        'description' => 'Optional list of translation groups to publish.',
                        'items' => [
                            'type' => 'string'
                        ]
                    ],
                    'all' => [
                        'type' => 'boolean',
                        'description' => 'Publish all translation groups.',
                        'default' => false
                    ]
                ]
            ]
        );
    }

    /**
     * @param array<int, mixed>|null $languages
     *
     * @return array{data: array<int, array<string, mixed>>, page: int, limit: int, count: int|string, total: int|string, languages: list<string>}
     *
     * @throws QUI\Exception
     */
    protected static function getLanguageVariables(
        ?int $limit,
        ?int $page,
        ?array $languages,
        ?string $group
    ): array {
        $limit = self::normalizeLimit($limit);
        $page = self::normalizePage($page);
        $where = [];

        if (is_string($group) && $group !== '') {
            $where['groups'] = $group;
        }

        $query = [
            'from' => QUI\Translator::table(),
            'limit' => self::createLimit($limit, $page)
        ];

        if (!empty($where)) {
            $query['where'] = $where;
        }

        $countQuery = $query;
        $countQuery['count'] = 'id';
        unset($countQuery['limit']);

        $data = QUI::getDataBase()->fetch($query);
        $count = QUI::getDataBase()->fetch($countQuery);
        $availableLanguages = QUI\Translator::langs();
        $selectedLanguages = self::filterLanguages($languages, $availableLanguages);

        return [
            'data' => self::filterRows($data, $selectedLanguages),
            'page' => $page,
            'limit' => $limit,
            'count' => $count[0]['id'] ?? 0,
            'total' => $count[0]['id'] ?? 0,
            'languages' => $selectedLanguages
        ];
    }

    /**
     * @param array<int, mixed> $variables
     * @return array{saved: array<int, array<string, mixed>>, count: int, publishedGroups: list<string>}
     *
     * @throws QUI\Exception
     */
    protected static function saveLanguageVariables(array $variables, bool $publish): array
    {
        $saved = [];
        $groupsToPublish = [];

        foreach ($variables as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $group = trim((string)($variable['group'] ?? ''));
            $var = trim((string)($variable['var'] ?? ''));
            $package = trim((string)($variable['package'] ?? ''));
            $translations = $variable['translations'] ?? [];

            if ($group === '' || $var === '' || !is_array($translations)) {
                continue;
            }

            $datatype = (string)($variable['datatype'] ?? 'php,js');
            $html = !empty($variable['html']);
            $priority = (int)($variable['priority'] ?? 0);
            $entry = self::getExactEntry($group, $var, $package);

            if (empty($entry)) {
                QUI\Translator::add($group, $var, $package, $datatype, $html);
                $entry = self::getExactEntry($group, $var, $package);
            }

            $data = [
                'datatype' => $datatype,
                'html' => $html ? 1 : 0,
                'priority' => $priority
            ];

            foreach (QUI\Translator::langs() as $language) {
                if (array_key_exists($language, $translations)) {
                    $data[$language] = (string)$translations[$language];
                }
            }

            if (isset($entry['id'])) {
                QUI\Translator::editById((int)$entry['id'], $data);
            } else {
                QUI\Translator::edit($group, $var, $package, $data);
            }

            $groupsToPublish[$group] = true;
            $saved[] = [
                'group' => $group,
                'var' => $var,
                'package' => $package
            ];
        }

        $publishedGroups = [];

        if ($publish) {
            foreach (array_keys($groupsToPublish) as $group) {
                QUI\Translator::publish($group);
                $publishedGroups[] = $group;
            }
        }

        return [
            'saved' => $saved,
            'count' => count($saved),
            'publishedGroups' => $publishedGroups
        ];
    }

    /**
     * @param array<int, mixed>|null $languages
     * @param array<int, mixed>|null $fields
     *
     * @return array{data: array<int, array<string, mixed>>, page: int, limit: int, count: int|string, total: int|string, languages: list<string>}
     *
     * @throws QUI\Exception
     */
    protected static function searchLanguageVariables(
        string $search,
        ?int $limit,
        ?int $page,
        ?array $languages,
        ?array $fields
    ): array {
        $limit = self::normalizeLimit($limit);
        $page = self::normalizePage($page);
        $availableLanguages = QUI\Translator::langs();
        $selectedLanguages = self::filterLanguages($languages, $availableLanguages);
        $searchFields = self::filterSearchFields($fields, $availableLanguages);

        $result = QUI\Translator::getData(
            '',
            [
                'limit' => $limit,
                'page' => $page
            ],
            [
                'search' => $search,
                'fields' => $searchFields
            ]
        );

        return [
            'data' => self::filterRows($result['data'], $selectedLanguages),
            'page' => (int)$result['page'],
            'limit' => $limit,
            'count' => $result['count'],
            'total' => $result['total'],
            'languages' => $selectedLanguages
        ];
    }

    /**
     * @param array<int, mixed>|null $groups
     * @return array{publishedGroups: list<string>, count: int, scope: string}
     *
     * @throws QUI\Exception
     */
    protected static function publishLanguageVariables(?array $groups, bool $publishAll): array
    {
        $selectedGroups = [];

        if (!$publishAll && !empty($groups)) {
            foreach ($groups as $group) {
                if (!is_string($group)) {
                    continue;
                }

                $group = trim($group);

                if ($group === '') {
                    continue;
                }

                $selectedGroups[] = $group;
            }
        }

        if ($publishAll || empty($selectedGroups)) {
            $selectedGroups = QUI\Translator::getGroupList();
        }

        $selectedGroups = array_values(array_unique($selectedGroups));

        foreach ($selectedGroups as $group) {
            QUI\Translator::publish($group);
        }

        return [
            'publishedGroups' => $selectedGroups,
            'count' => count($selectedGroups),
            'scope' => $publishAll || empty($groups) ? 'all' : 'selection'
        ];
    }

    /**
     * @throws QUI\Exception
     */
    protected static function checkPermission(): void
    {
        Permission::checkPermission('quiqqer.admin', Server::getRequestUser());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws QUI\Database\Exception
     */
    protected static function getExactEntry(string $group, string $var, string $package): array
    {
        $result = QUI::getDataBase()->fetch([
            'from' => QUI\Translator::table(),
            'where' => [
                'groups' => $group,
                'var' => $var,
                'package' => $package
            ],
            'limit' => 1
        ]);

        return $result[0] ?? [];
    }

    protected static function normalizeLimit(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return 50;
        }

        return min($limit, 500);
    }

    protected static function normalizePage(?int $page): int
    {
        if ($page === null || $page < 1) {
            return 1;
        }

        return $page;
    }

    protected static function createLimit(int $limit, int $page): string
    {
        return (($page - 1) * $limit) . ',' . $limit;
    }

    /**
     * @param array<int, mixed>|null $languages
     * @param list<string> $availableLanguages
     * @return list<string>
     */
    protected static function filterLanguages(?array $languages, array $availableLanguages): array
    {
        if (empty($languages)) {
            return $availableLanguages;
        }

        $result = [];

        foreach ($languages as $language) {
            if (!is_string($language) || !in_array($language, $availableLanguages, true)) {
                continue;
            }

            $result[] = $language;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, mixed>|null $fields
     * @param list<string> $availableLanguages
     * @return list<string>
     */
    protected static function filterSearchFields(?array $fields, array $availableLanguages): array
    {
        $allowedFields = array_merge(self::BASE_FIELDS, $availableLanguages);

        foreach ($availableLanguages as $language) {
            $allowedFields[] = $language . '_edit';
        }

        if (empty($fields)) {
            return $allowedFields;
        }

        $result = [];

        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, $allowedFields, true)) {
                continue;
            }

            $result[] = $field;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $languages
     * @return array<int, array<string, mixed>>
     */
    protected static function filterRows(array $rows, array $languages): array
    {
        $keep = array_flip(array_merge(self::BASE_FIELDS, $languages));

        foreach ($languages as $language) {
            $keep[$language . '_edit'] = true;
        }

        return array_map(static function (array $row) use ($keep): array {
            return array_intersect_key($row, $keep);
        }, $rows);
    }
}
