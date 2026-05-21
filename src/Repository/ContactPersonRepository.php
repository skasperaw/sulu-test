<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactPerson;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContactPersonRepository extends ServiceEntityRepository implements ContactPersonRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactPerson::class);
    }

    public function findById(int $id): ?ContactPerson
    {
        return $this->find($id);
    }

    public function findAll(): array
    {
        return parent::findAll();
    }

    public function create(): ContactPerson
    {
        return new ContactPerson();
    }

    // Flushes the entire Unit of Work — intentional for single-entity CRUD operations.
    public function save(ContactPerson $contactPerson): void
    {
        $this->getEntityManager()->persist($contactPerson);
        $this->getEntityManager()->flush();
    }

    // Flushes the entire Unit of Work — intentional for single-entity CRUD operations.
    public function remove(ContactPerson $contactPerson): void
    {
        $this->getEntityManager()->remove($contactPerson);
        $this->getEntityManager()->flush();
    }
}
