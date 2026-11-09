<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class BookingCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Réservation')->setEntityLabelInPlural('Réservations')->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('trip', 'Trajet');
        yield AssociationField::new('passenger', 'Passager');
        yield Field::new('boardingStop', 'Montée')->formatValue(static fn ($stop): string => (string) $stop?->getCity());
        yield Field::new('alightingStop', 'Descente')->formatValue(static fn ($stop): string => (string) $stop?->getCity());
        yield IntegerField::new('seats', 'Places');
        yield MoneyField::new('seatPriceCents', 'Prix d\'une place')->setCurrency('MAD')->setStoredAsCents()->hideOnIndex();
        yield MoneyField::new('totalPriceCents', 'Total')->setCurrency('MAD')->setStoredAsCents();
        yield TextField::new('statusLabel', 'État')->setSortable(false);
        yield DateTimeField::new('createdAt', 'Réservée le');
        yield DateTimeField::new('cancelledAt', 'Annulée le')->hideOnIndex();
    }
}
