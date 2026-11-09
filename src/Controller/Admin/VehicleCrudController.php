<?php

namespace App\Controller\Admin;

use App\Entity\Vehicle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class VehicleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Vehicle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Véhicule')->setEntityLabelInPlural('Véhicules');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        $vehicle = $this->getContext()?->getEntity()->getInstance();
        $usedByTrip = $vehicle instanceof Vehicle && !$vehicle->getTrips()->isEmpty();

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('owner', 'Propriétaire')
            ->setFormTypeOption('disabled', $usedByTrip)
            ->setHelp($usedByTrip ? 'Ce véhicule est utilisé par un trajet : son propriétaire ne peut plus changer.' : '');
        yield TextField::new('brand', 'Marque');
        yield TextField::new('model', 'Modèle');
        yield IntegerField::new('passengerSeats', 'Places passagers');
    }
}
