<?php

namespace Nuwave\Lighthouse\Schema\Directives\Fields;

use GraphQL\Deferred;
use Illuminate\Database\Eloquent\Model;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Execution\DataLoader\BatchLoader;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Execution\DataLoader\RelationBatchLoader;

class LoadRelationDirective extends RelationDirective implements FieldMiddleware
{
    /**
     * Name of the directive.
     *
     * @return string
     */
    public function name(): string
    {
        return 'loadRelation';
    }

    /**
     * Eager load a relation on the parent instance.
     *
     * @param FieldValue $value
     * @param \Closure   $next
     *
     * @return FieldValue
     */
    public function handleField(FieldValue $value, \Closure $next): FieldValue
    {
        $resolver = $value->getResolver();

        return $next(
            $value->setResolver(
                function (Model $parent, array $resolveArgs, $context = null, ResolveInfo $resolveInfo) use ($resolver) {
                    $loader = BatchLoader::instance(
                        RelationBatchLoader::class,
                        $resolveInfo->path,
                        $this->getLoaderConstructorArguments($parent, $resolveArgs, $context, $resolveInfo)
                    );

                    return new Deferred(function () use ($loader, $resolver, $parent, $resolveArgs, $context, $resolveInfo) {
                        return $loader->load(
                            $parent->getKey(),
                            ['parent' => $parent]
                        )->then(
                            function () use ($resolver, $parent, $resolveArgs, $context, $resolveInfo) {
                                return $resolver($parent, $resolveArgs, $context, $resolveInfo);
                            }
                        );
                    });
                }
            )
        );
    }
}
