<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

final class ReviewCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return Review::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Avis')->setEntityLabelInPlural('Avis')->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('trip', 'Trajet');
        yield AssociationField::new('author', 'Auteur');
        yield AssociationField::new('target', 'Destinataire');
        yield IntegerField::new('rating', 'Note');
        yield TextareaField::new('comment', 'Commentaire');
        yield DateTimeField::new('createdAt', 'Déposé le');
    }
}
