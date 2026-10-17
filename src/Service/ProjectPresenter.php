<?php

namespace App\Service;

use App\Entity\Project;

final class ProjectPresenter
{
    public function present(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'label' => $project->getLabel(),
            'address' => $project->getAddress(),
            'numberOfFloors' => $project->getNumberOfFloors(),
            'postalCode' => $project->getPostalCode(),
            'deliveryDate' => $project->getDeliveryDate()?->format('Y-m-d H:i:s'),
            'picture' => $project->getPicture(),
        ];
    }
}