<?php

namespace App\Enum;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Viewer = 'viewer';
}