<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder as ScoutBuilder;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Select\SelectHelper;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AllDirective extends BaseDirective implements FieldResolver
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Fetch all Eloquent models and return the collection as the result.
"""
directive @all(
  """
  Specify the class name of the model to use.
  This is only needed when the default model detection does not work.
  """
  model: String

  """
  Point to a function that provides a Query Builder instance.
  This replaces the use of a model.
  """
  builder: String

  """
  Apply scopes to the underlying query.
  """
  scopes: [String!]
) on FIELD_DEFINITION
GRAPHQL;
    }

    public function resolveField(FieldValue $fieldValue): FieldValue
    {
        $fieldValue->setResolver(function ($root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Collection {
            if ($this->directiveHasArgument('builder')) {
                $builderResolver = $this->getResolverFromArgument('builder');

                $query = $builderResolver($root, $args, $context, $resolveInfo);
                assert(
                    $query instanceof QueryBuilder || $query instanceof EloquentBuilder || $query instanceof ScoutBuilder || $query instanceof Relation,
                    "The method referenced by the builder argument of the @{$this->name()} directive on {$this->nodeName()} must return a Builder or Relation."
                );
            } else {
                $query = $this->getModelClass()::query();
            }

            $builder = $resolveInfo
                ->argumentSet
                ->enhanceBuilder(
                    $query,
                    $this->directiveArgValue('scopes', [])
                );

            if (config('lighthouse.optimized_selects')) {
                if (($builder instanceof QueryBuilder || $builder instanceof EloquentBuilder) && ! $this->directiveHasArgument('builder')) {
                    $fieldSelection = array_keys($resolveInfo->getFieldSelection(1));

                    $selectColumns = SelectHelper::getSelectColumns(
                        $this->definitionNode,
                        $fieldSelection,
                        $this->getModelClass()
                    );

                    if (empty($selectColumns)) {
                        return $builder->get();
                    }

                    $query = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;

                    if (null !== $query->columns) {
                        $bindings = $query->getRawBindings();

                        $expressions = array_filter($query->columns, function ($column) {
                            return $column instanceof Expression;
                        });

                        $builder = $builder->select(array_unique(array_merge($selectColumns, $expressions)));

                        foreach ($bindings as $type => $binding) {
                            $builder = $builder->addBinding($binding, $type);
                        }
                    } else {
                        $builder = $builder->select($selectColumns);
                    }
                }
            }

            return $builder->get();
        });

        return $fieldValue;
    }
}
