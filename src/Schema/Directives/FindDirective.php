<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Select\SelectHelper;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FindDirective extends BaseDirective implements FieldResolver
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Find a model based on the arguments provided.
"""
directive @find(
  """
  Specify the class name of the model to use.
  This is only needed when the default model detection does not work.
  """
  model: String

  """
  Apply scopes to the underlying query.
  """
  scopes: [String!]
) on FIELD_DEFINITION
GRAPHQL;
    }

    public function resolveField(FieldValue $fieldValue): FieldValue
    {
        $fieldValue->setResolver(function ($root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ?Model {
            $builder = $resolveInfo
                ->argumentSet
                ->enhanceBuilder(
                    $this->getModelClass()::query(),
                    $this->directiveArgValue('scopes', [])
                );

            if (config('lighthouse.optimized_selects')) {
                $fieldSelection = array_keys($resolveInfo->getFieldSelection(1));

                $selectColumns = SelectHelper::getSelectColumns(
                    $this->definitionNode,
                    $fieldSelection,
                    $this->getModelClass()
                );

                if (! empty($selectColumns)) {
                    $builder = $builder->select($selectColumns);
                }
            }

            $results = $builder->get();

            if ($results->count() > 1) {
                throw new Error('The query returned more than one result.');
            }

            return $results->first();
        });

        return $fieldValue;
    }
}
