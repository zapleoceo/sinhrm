<?php

declare(strict_types=1);

namespace Tests\Unit\HiringRequests;

use App\Modules\HiringRequests\Exceptions\HiringException;
use App\Modules\HiringRequests\Support\FormFields;
use PHPUnit\Framework\TestCase;

/** The configurable request form: declared keys only, types, required fields. */
final class FormFieldsTest extends TestCase
{
    private const array FIELDS = [
        ['key' => 'budget', 'label' => 'Budget', 'type' => 'number', 'required' => true],
        ['key' => 'remote', 'label' => 'Remote', 'type' => 'checkbox', 'required' => true],
        ['key' => 'start', 'label' => 'Start', 'type' => 'date', 'required' => false],
        ['key' => 'grade', 'label' => 'Grade', 'type' => 'select', 'required' => false, 'options' => ['junior', 'senior']],
        ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
    ];

    public function test_clean_and_missing(): void
    {
        $clean = FormFields::clean(self::FIELDS, ['budget' => '1500', 'remote' => false, 'grade' => 'senior', 'notes' => '', 'x' => 'dropped']);
        $this->assertSame(['budget' => 1500, 'remote' => false, 'grade' => 'senior'], $clean);
        $this->assertSame(['remote'], FormFields::missing(self::FIELDS, $clean), 'an unticked required checkbox is missing');
        $this->assertSame(['budget', 'remote'], FormFields::missing(self::FIELDS, []));
    }

    public function test_type_errors(): void
    {
        foreach ([['budget' => 'abc'], ['remote' => 'yes'], ['start' => '01.10.2026'], ['grade' => 'lead']] as $bad) {
            try {
                FormFields::clean(self::FIELDS, $bad);
                $this->fail('expected invalid_field for '.json_encode($bad));
            } catch (HiringException $e) {
                $this->assertSame('invalid_field', $e->errorCode);
                $this->assertSame(array_key_first($bad), $e->extra['field']);
            }
        }
    }
}
