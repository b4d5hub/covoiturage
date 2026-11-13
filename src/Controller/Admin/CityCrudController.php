<?php

namespace App\Controller\Admin;

use App\Entity\City;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Les villes proviennent d'OpenStreetMap, à la publication des trajets : le back-office
 * ne permet que de consulter et de corriger le nom affiché.
 */
final class CityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return City::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Ville')->setEntityLabelInPlural('Villes')->setDefaultSort(['name' => 'ASC'])
            ->setHelp('index', 'Les villes sont créées automatiquement d\'après les points de rendez-vous des trajets publiés.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom affiché');
        yield TextField::new('osmReference', 'Objet OpenStreetMap')->hideOnForm();
        yield TextField::new('centreLabel', 'Centre')->hideOnForm()->hideOnIndex();
    }
}
