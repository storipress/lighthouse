<?php

namespace Nuwave\Lighthouse\Select;

use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Schema\AST\ASTBuilder;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Support\AppVersion;
use Nuwave\Lighthouse\Support\Utils;

class SelectHelper
{
    public const DIRECTIVES_REQUIRING_LOCAL_KEY = ['hasOne', 'hasMany', 'count', 'morphOne', 'morphMany'];

    public const DIRECTIVES_REQUIRING_FOREIGN_KEY = ['belongsTo'];

    public const DIRECTIVES_RETURN = ['morphTo', 'morphToMany'];

    public const DIRECTIVES_IGNORE = ['aggregate', 'withCount', 'belongsToMany'];

    public const DIRECTIVES = [
        'aggregate',
        'belongsTo',
        'belongsToMany',
        'count',
        'hasOne',
        'hasMany',
        'morphOne',
        'morphMany',
        'morphTo',
        'morphToMany',
        'withCount',
    ];

    /**
     * Given a field definition node, resolve info, and a model name, return the SQL columns that should be selected.
     * Accounts for relationships and to rename and select directives.
     *
     * @param array<int, string> $fieldSelection
     *
     * @return array<int, string>
     *
     * @reference https://github.com/nuwave/lighthouse/pull/1626
     */
    public static function getSelectColumns(Node $definitionNode, array $fieldSelection, string $modelName): array
    {
        $returnTypeName = ASTHelper::getUnderlyingTypeName($definitionNode);

        $documentAST = app(ASTBuilder::class)->documentAST();

        assert($documentAST instanceof DocumentAST);

        if (Str::contains($returnTypeName, ['SimplePaginator', 'Paginator'])) {
            $returnTypeName = str_replace(['SimplePaginator', 'Paginator'], '', $returnTypeName);
        }

        $type = $documentAST->types[$returnTypeName];

        if ($type instanceof UnionTypeDefinitionNode) {
            $type = $documentAST->types[ASTHelper::getUnderlyingTypeName($type->types[0])];
        }

        $fieldDefinitions = $type->fields;

        assert($fieldDefinitions instanceof NodeList);

        /** @var Model $model */
        $model = new $modelName();

        assert($model instanceof Model);

        $selectColumns = [];

        foreach ($fieldSelection as $field) {
            $fieldDefinition = ASTHelper::firstByName($fieldDefinitions, $field);

            if ($fieldDefinition) {
                foreach (self::DIRECTIVES as $directiveType) {
                    if (ASTHelper::hasDirective($fieldDefinition, $directiveType)) {
                        /** @var DirectiveNode $directive */
                        $directive = ASTHelper::directiveDefinition($fieldDefinition, $directiveType);

                        if (in_array($directiveType, self::DIRECTIVES_RETURN)) {
                            return [];
                        }

                        if (in_array($directiveType, self::DIRECTIVES_REQUIRING_LOCAL_KEY)) {
                            $relationName = ASTHelper::directiveArgValue($directive, 'relation', $field);

                            if (method_exists($model, $relationName)) {
                                $relation = $model->{$relationName}();

                                $localKey = AppVersion::below(5.7)
                                    ? Utils::accessProtected($relation, 'localKey')
                                    : $relation->getLocalKeyName();

                                $selectColumns[] = $localKey;
                            }
                        }

                        if (in_array($directiveType, self::DIRECTIVES_REQUIRING_FOREIGN_KEY)) {
                            $relationName = ASTHelper::directiveArgValue($directive, 'relation', $field);

                            if (method_exists($model, $relationName)) {
                                $foreignKey = AppVersion::below(5.8)
                                    ? $model->{$relationName}()->getForeignKey()
                                    : $model->{$relationName}()->getForeignKeyName();

                                $selectColumns[] = $foreignKey;
                            }
                        }

                        continue 2;
                    }
                }

                if ($directive = ASTHelper::directiveDefinition($fieldDefinition, 'select')) {
                    // append selected columns in select directive to selection
                    $selectFields = ASTHelper::directiveArgValue($directive, 'columns', []);
                    $selectColumns = array_merge($selectColumns, $selectFields);
                } elseif ($directive = ASTHelper::directiveDefinition($fieldDefinition, 'rename')) {
                    // append renamed attribute to selection
                    $renamedAttribute = ASTHelper::directiveArgValue($directive, 'attribute');
                    $selectColumns[] = $renamedAttribute;
                } else {
                    // fallback to selecting the field name
                    $selectColumns[] = $field;
                }
            }
        }

        /** @var array<int, string> $selectColumns */
        $selectColumns = array_filter($selectColumns, function ($column) use ($model): bool {
            return ! $model->hasGetMutator($column) && ! method_exists($model, $column);
        });

        return array_unique($selectColumns);
    }
}
