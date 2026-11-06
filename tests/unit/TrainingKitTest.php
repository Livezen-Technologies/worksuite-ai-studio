<?php

namespace Tests\Unit;

use App\Services\TrainingService;
use CodeIgniter\Test\CIUnitTestCase;

final class TrainingKitTest extends CIUnitTestCase
{
    public function testInputValidation(): void
    {
        $this->assertNull(TrainingService::nameProblem('my-run_1.0'));
        $this->assertNotNull(TrainingService::nameProblem('x"); import os #'));
        $this->assertNull(TrainingService::baseModelProblem(TrainingService::BASE_MODELS[0]));
        $this->assertNotNull(TrainingService::baseModelProblem('evil", trust_remote_code=True, x="'));
        $this->assertSame(['epochs' => 2.0, 'lora_rank' => 8], TrainingService::cleanHyperparams(['epochs' => '2', 'lora_rank' => 8, 'bogus' => 1]));
        foreach ([['seed' => '1; import os'], ['lora_rank' => 0], ['batch_size' => 1.5], ['quant_export' => 'q4"']] as $bad) {
            try { TrainingService::cleanHyperparams($bad); $this->fail(json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testGeneratorEscapesOldRows(): void
    {
        $job = ['name' => 'x"""\nimport os', 'method' => 'qlora', 'base_model' => "a\"\nimport os\nx=\""];
        $hp = array_merge(TrainingService::DEFAULT_HYPERPARAMS, ['seed' => '1)\nimport os\n(', 'quant_export' => "q\"\nimport os\n\""]);
        $m = new \ReflectionMethod(TrainingService::class, 'trainScript');
        $py = $m->invoke(new TrainingService(), $job, $hp);
        $this->assertStringNotContainsString("\nimport os", $py);
        $this->assertStringContainsString('model_name="a\"\nimport os\nx=\""', $py);
        $this->assertStringContainsString('random_state=1,', $py);
    }
}
