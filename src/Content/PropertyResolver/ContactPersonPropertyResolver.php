<?php

declare(strict_types=1);

namespace App\Content\PropertyResolver;

use App\Admin\ContactPersonAdmin;
use App\Content\ResourceLoader\ContactPersonResourceLoader;
use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;

class ContactPersonPropertyResolver implements PropertyResolverInterface
{
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        if (!\is_int($data) || $data < 1) {
            return ContentView::create(null, \array_merge(['id' => null], $params));
        }

        return ContentView::createResolvableWithReferences(
            $data,
            ContactPersonResourceLoader::RESOURCE_LOADER_KEY,
            ContactPersonAdmin::RESOURCE_KEY,
            \array_merge(['id' => $data], $params),
        );
    }

    public static function getType(): string
    {
        return 'single_contact_person';
    }
}
