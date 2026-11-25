<?php

namespace App\Node;

enum ProposalKind: string
{
    /** The recipient becomes the owner. */
    case Owner = 'owner';
    /** The recipient becomes the Tech-C; the owner stays. */
    case TechC = 'techc';
}
