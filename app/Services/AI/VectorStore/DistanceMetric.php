<?php

namespace App\Services\AI\VectorStore;

enum DistanceMetric: string
{
    case Cosine = 'Cosine';

    case Euclid = 'Euclid';

    case Dot = 'Dot';

    case Manhattan = 'Manhattan';
}
