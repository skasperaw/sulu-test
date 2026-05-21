<?php

declare(strict_types=1);

namespace App\Content\ResourceLoader;

use App\Repository\ContactPersonRepositoryInterface;
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;

class ContactPersonResourceLoader implements ResourceLoaderInterface
{
    public const RESOURCE_LOADER_KEY = 'contact_person';

    public function __construct(
        private readonly ContactPersonRepositoryInterface $repository,
    ) {
    }

    // $locale intentionally ignored — ContactPerson is not localised
    public function load(array $ids, ?string $locale, array $params = []): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (!\is_int($id) && !\ctype_digit($id)) {
                continue;
            }

            $contactPerson = $this->repository->findById((int) $id);
            if ($contactPerson !== null) {
                $result[$id] = $contactPerson;
            }
        }

        return $result;
    }

    public static function getKey(): string
    {
        return self::RESOURCE_LOADER_KEY;
    }
}
