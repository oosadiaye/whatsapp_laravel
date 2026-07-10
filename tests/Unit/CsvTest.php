<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public function test_prefixes_formula_trigger_characters(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", Csv::safe('=HYPERLINK("x")'));
        $this->assertSame("'+2348012345678", Csv::safe('+2348012345678'));
        $this->assertSame("'-1+1", Csv::safe('-1+1'));
        $this->assertSame("'@SUM(A1)", Csv::safe('@SUM(A1)'));
    }

    public function test_leaves_safe_values_unchanged(): void
    {
        $this->assertSame('Adebayo Okonkwo', Csv::safe('Adebayo Okonkwo'));
        $this->assertSame('2348012345678', Csv::safe('2348012345678'));
        $this->assertSame('', Csv::safe(''));
        $this->assertSame('', Csv::safe(null));
    }
}
