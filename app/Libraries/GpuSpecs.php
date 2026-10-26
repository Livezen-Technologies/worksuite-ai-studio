<?php

namespace App\Libraries;

/**
 * CUDA cores per GPU from the model name, as NVIDIA publishes them. Neither Ollama nor nvidia-smi reports the core
 * count, so the Servers page derives it: cores per GPU × gpu_count. Unknown models return null (shown as "—").
 * Longest name first, so "RTX 4090 D" is not read as "RTX 4090".
 */
class GpuSpecs
{
    private const CUDA_CORES = [
        // data centre / workstation
        'H200' => 16896, 'H100 PCIe' => 14592, 'H100' => 16896, 'A100' => 6912, 'A40' => 10752, 'A30' => 3584, 'A16' => 1280,
        'A10G' => 9216, 'A10' => 9216, 'A2' => 1280, 'L40S' => 18176, 'L40' => 18176, 'L4' => 7424, 'T4' => 2560, 'V100' => 5120,
        'P100' => 3584, 'P40' => 3840, 'P4' => 2560,
        'RTX 6000 Ada' => 18176, 'RTX 5000 Ada' => 12800, 'RTX 4500 Ada' => 7680, 'RTX 4000 Ada' => 6144, 'RTX 2000 Ada' => 2816,
        'RTX A6000' => 10752, 'RTX A5500' => 10240, 'RTX A5000' => 8192, 'RTX A4500' => 7168, 'RTX A4000' => 6144, 'RTX A2000' => 3328,
        'Quadro RTX 8000' => 4608, 'Quadro RTX 6000' => 4608, 'Quadro RTX 5000' => 3072, 'Quadro RTX 4000' => 2304,
        'Quadro T2000' => 1024, 'T1200' => 1024, 'Quadro T1000' => 896, 'T1000' => 896, 'T600' => 640, 'T400' => 384,
        'Quadro P6000' => 3840, 'Quadro P5000' => 2560, 'Quadro P4000' => 1792, 'Quadro P2200' => 1280, 'Quadro P2000' => 1024, 'Quadro P1000' => 640, 'Quadro P620' => 512, 'Quadro P400' => 256,
        // GeForce
        'RTX 5090' => 21760, 'RTX 5080' => 10752, 'RTX 5070 Ti' => 8960, 'RTX 5070' => 6144, 'RTX 5060 Ti' => 4608, 'RTX 5060' => 3840,
        'RTX 4090 D' => 14592, 'RTX 4090' => 16384, 'RTX 4080 SUPER' => 10240, 'RTX 4080' => 9728, 'RTX 4070 Ti SUPER' => 8448, 'RTX 4070 Ti' => 7680,
        'RTX 4070 SUPER' => 7168, 'RTX 4070' => 5888, 'RTX 4060 Ti' => 4352, 'RTX 4060' => 3072,
        'RTX 3090 Ti' => 10752, 'RTX 3090' => 10496, 'RTX 3080 Ti' => 10240, 'RTX 3080' => 8704, 'RTX 3070 Ti' => 6144, 'RTX 3070' => 5888,
        'RTX 3060 Ti' => 4864, 'RTX 3060' => 3584, 'RTX 3050' => 2560,
        'RTX 2080 Ti' => 4352, 'RTX 2080 SUPER' => 3072, 'RTX 2080' => 2944, 'RTX 2070 SUPER' => 2560, 'RTX 2070' => 2304, 'RTX 2060 SUPER' => 2176, 'RTX 2060' => 1920,
        'GTX 1660 Ti' => 1536, 'GTX 1660 SUPER' => 1408, 'GTX 1660' => 1408, 'GTX 1650 SUPER' => 1280, 'GTX 1650' => 896,
        'GTX 1080 Ti' => 3584, 'GTX 1080' => 2560, 'GTX 1070 Ti' => 2432, 'GTX 1070' => 1920, 'GTX 1060' => 1280, 'GTX 1050 Ti' => 768, 'GTX 1050' => 640,
    ];

    public static function cudaCores(?string $model): ?int
    {
        $m = strtoupper((string) $model);
        if ($m === '' || ! str_contains($m, 'NVIDIA') && ! preg_match('/RTX|GTX|QUADRO|TESLA|\b[AHLTPV]\d/', $m)) return null;
        $names = array_keys(self::CUDA_CORES);
        usort($names, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($names as $n) {
            if (preg_match('/(^|[^A-Z0-9])' . preg_quote(strtoupper($n), '/') . '($|[^A-Z0-9])/', $m)) return self::CUDA_CORES[$n];
        }
        return null;
    }

    /** Total CUDA cores of a server row (cores per GPU × gpu_count), or null. */
    public static function forServer(array $s): ?int
    {
        $per = self::cudaCores($s['gpu_model'] ?? null);
        return $per === null ? null : $per * max(1, (int) ($s['gpu_count'] ?? 1));
    }
}
