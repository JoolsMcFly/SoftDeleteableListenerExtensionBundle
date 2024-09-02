<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use DateTime;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Annotation;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDeleteSuccessor;
use Exception;
use Gedmo\Mapping\Annotation\SoftDeleteable;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Gedmo\SoftDeleteable\Event\PreSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    use ContainerAwareTrait;

    /**
     * @var Reader
     */
    protected $reader;

    protected $entityManager;

    public function __construct(Reader $reader, EntityManagerInterface $entityManager)
    {
        $this->reader = $reader;
        $this->entityManager = $entityManager;
    }

    /**
     *
     * @throws OnSoftDeleteUnknownTypeException
     * @throws Exception
     */
    public function preSoftDelete(PreSoftDeleteEventArgs $args): void
    {
        $em = $this->entityManager;
        $entity = $args->getObject();

        $entityReflection = new ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        $reader = new AnnotationReader();
        foreach ($namespaces as $namespace) {
            $reflectionClass = new ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                /** @var onSoftDelete $onDelete */
                if ($onDelete = $reader->getPropertyAnnotation($property, onSoftDelete::class)) {
                    $objects = null;
                    $manyToMany = null;
                    $manyToOne = null;
                    $oneToOne = null;

                    $associationMapping = null;
                    if (array_key_exists($property->getName(), $meta->getAssociationMappings())) {
                        $associationMapping = (object)$meta->getAssociationMapping($property->getName());
                    }

                    if (
                        ($manyToMany = $associationMapping && $associationMapping->type == ClassMetadataInfo::MANY_TO_MANY ? $associationMapping : null) ||
                        ($manyToOne = $associationMapping && $associationMapping->type == ClassMetadataInfo::MANY_TO_ONE ? $associationMapping : null) ||
                        ($oneToOne = $associationMapping && $associationMapping->type == ClassMetadataInfo::ONE_TO_ONE ? $associationMapping : null)
                    ) {
                        /** @var OneToOne|OneToMany|ManyToMany $relationship */
                        $relationship = $manyToOne ?: $manyToMany ?: $oneToOne;

                        $ns = null;
                        $nsOriginal = $relationship->targetEntity;
                        $nsFromRelativeToAbsolute = $entityReflection->getNamespaceName().'\\'.$relationship->targetEntity;
                        $nsFromRoot = '\\'.$relationship->targetEntity;
                        if (class_exists($nsOriginal)){
                            $ns = $nsOriginal;
                        } elseif (class_exists($nsFromRoot)){
                            $ns = $nsFromRoot;
                        } elseif (class_exists($nsFromRelativeToAbsolute)){
                            $ns = $nsFromRelativeToAbsolute;
                        }

                        if (!$this->isOnDeleteTypeSupported($onDelete, $relationship)) {
                            throw new Exception(sprintf('%s is not supported for %s relationships', $onDelete->type, get_class($relationship)));
                        }

                        if (($manyToOne || $oneToOne) && $ns && $entity instanceof $ns) {
                            $objects = $em->getRepository($namespace)->findBy(array(
                                $property->name => $entity,
                            ));
                        } elseif ($manyToMany) {
                            // For ManyToMany relations, we only delete the relationship between
                            // two entities. This can be done on both side of the relation.
                            $allowMappedSide = get_class($entity) === $namespace;
                            $allowInversedSide = ($ns && $entity instanceof $ns);
                            if ($allowInversedSide) {
                                $mtmRelations = $em->getRepository($namespace)->createQueryBuilder('entity')
                                    ->innerJoin(sprintf('entity.%s', $property->name), 'mtm')
                                    ->addSelect('mtm')
                                    ->andWhere(sprintf(':entity MEMBER OF entity.%s', $property->name))
                                    ->setParameter('entity', $entity)
                                    ->getQuery()
                                    ->getResult();

                                foreach ($mtmRelations as $mtmRelation) {
                                    try {
                                        $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                        $collection = $propertyAccessor->getValue($mtmRelation, $property->name);
                                        $collection->removeElement($entity);
                                    } catch (Exception $e) {
                                        throw new Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($mtmRelation)));
                                    }
                                }
                            } elseif ($allowMappedSide) {
                                try {
                                    $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                    $collection = $propertyAccessor->getValue($entity, $property->name);
                                    $collection->clear();
                                    continue;
                                } catch (Exception $e) {
                                    throw new Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($entity)));
                                }
                            }
                        }
                    }

                    if ($objects) {
                        $reflectionClass = new ReflectionClass($namespace);
                        $classAnnotation = $this->reader->getClassAnnotation($reflectionClass, SoftDeleteable::class);
                        $softDelete = $classAnnotation instanceof SoftDeleteable;
                        foreach ($objects as $object) {
                            $this->processOnDeleteOperation($object, $onDelete, $property, $meta, $softDelete,
                                ['fieldName' => $classAnnotation->fieldName]);
                        }
                    }
                }
            }
        }
    }

    /**
     * @throws OnSoftDeleteUnknownTypeException
     * @throws Exception
     */
    protected function processOnDeleteOperation(
        $object,
        onSoftDelete $onDelete,
        ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        $config
    ): void {
        if (strtoupper($onDelete->type) === 'SET NULL') {
            $this->processOnDeleteSetNullOperation($object, $property, $meta);
        } elseif (strtoupper($onDelete->type) === 'CASCADE') {
            $this->processOnDeleteCascadeOperation($object, $softDelete, $config);
        } elseif (strtoupper($onDelete->type) === 'SUCCESSOR') {
            $this->processOnDeleteSuccessorOperation($object, $property, $meta);
        } else {
            throw new OnSoftDeleteUnknownTypeException($onDelete->type);
        }
    }

    protected function processOnDeleteSetNullOperation(
        $object,
        ReflectionProperty $property,
        ClassMetadata $meta
    ): void {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reflProp->setValue($object, null);
        $this->entityManager->persist($object);

        $this->entityManager->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, null);
        $this->entityManager->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, null),
        ));
    }

    /**
     * @throws Exception
     */
    protected function processOnDeleteSuccessorOperation(
        $object,
        ReflectionProperty $property,
        ClassMetadata $meta
    ): void {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reader = new AnnotationReader();
        $reflectionClass = new ReflectionClass(ClassUtils::getClass($oldValue));
        $successors = [];
        foreach ($reflectionClass->getProperties() as $propertyOfOldValueObject) {
            if ($reader->getPropertyAnnotation($propertyOfOldValueObject, onSoftDeleteSuccessor::class)) {
                $successors[] = $propertyOfOldValueObject;
            }
        }

        if (count($successors) > 1) {
            throw new Exception('Only one property of deleted entity can be marked as successor.');
        } elseif (empty($successors)) {
            throw new Exception('One property of deleted entity must be marked as successor.');
        }

        $successors[0]->setAccessible(true);

        if ($oldValue instanceof Proxy) {
            $oldValue->__load();
        }

        $newValue = $successors[0]->getValue($oldValue);
        $reflProp->setValue($object, $newValue);
        $this->entityManager->persist($object);

        $this->entityManager->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, $newValue);
        $this->entityManager->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, $newValue),
        ));
    }

    protected function processOnDeleteCascadeOperation(
        $object,
        $softDelete,
        $config
    ): void {
        if ($softDelete) {
            $this->softDeleteCascade($config, $object);
        } else {
            $this->entityManager->remove($object);
        }
    }

    protected function softDeleteCascade($config, $object): void
    {
        $meta = $this->entityManager->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof Datetime) {
            return;
        }

        //trigger event to check next level
        $this->entityManager->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::PRE_SOFT_DELETE,
            new PreSoftDeleteEventArgs($object, $this->entityManager)
        );

        $date = new DateTime();
        $reflProp->setValue($object, $date);

        $uow = $this->entityManager->getUnitOfWork();
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
        $uow->scheduleExtraUpdate($object, array(
            $config['fieldName'] => array($oldValue, $date),
        ));

        $this->entityManager->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::POST_SOFT_DELETE,
            new PreSoftDeleteEventArgs($object, $this->entityManager)
        );
    }

    protected function isOnDeleteTypeSupported(onSoftDelete $onDelete, $relationship): bool
    {
        if (strtoupper($onDelete->type) === 'SET NULL' && ($relationship instanceof ManyToMany || (property_exists($relationship, 'type') && $relationship->type === ClassMetadataInfo::MANY_TO_MANY))) {
            return false;
        }

        return true;
    }
}
