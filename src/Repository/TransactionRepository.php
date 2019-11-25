<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Smart\CoreBundle\Doctrine\RepositoryTrait;

class TransactionRepository extends EntityRepository
{
    use RepositoryTrait\FindByQuery;

    /**
     * @param User $user
     *
     * @return int
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getIncomingSum(User $user): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('SUM(e.sum)')
            ->where('e.to_user = :user');
        $qb->setParameter('user', $user);

        $query = $qb->getQuery();

        return (int) $query->getSingleScalarResult();
    }

    /**
     * @param User $user
     *
     * @return int
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getOutgoingSum(User $user): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('SUM(e.sum)')
            ->where('e.from_user = :user');
        $qb->setParameter('user', $user);

        $query = $qb->getQuery();

        return (int) $query->getSingleScalarResult();
    }
}
