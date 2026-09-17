<?php

namespace App\Services\Academic;

/**
 * Raised by the GradeAggregationService when an assessment carries an
 * un-normalizable domain value (max_score <= 0) that would otherwise
 * produce division by zero, NaN, or infinity.
 */
class GradeAggregationException extends \DomainException {}
