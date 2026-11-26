<?php

namespace App\Commands;

use App\Libraries\Activity;
use App\Libraries\OpenRouter;
use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// lists openrouter models that take images, flags the ones that may train on data, probes and picks the image model
class Models extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:models';
    protected $description = 'Shows image capable OpenRouter models, flags models that may train on our data, and picks the image model.';
    protected $usage       = 'helpdesk:models [--probe 8] [--apply] [--all]';

    // 1x1 png used to prove an endpoint accepts images without sending real data
    private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function run(array $params)
    {
        if (! OpenRouter::configured()) {
            CLI::error('OPENROUTER_API_KEY is not set');

            return;
        }

        $client = new OpenRouter();
        $models = $client->models();

        $vision = array_values(array_filter($models, [OpenRouter::class, 'takesImages']));
        $flagged = array_values(array_filter($vision, [OpenRouter::class, 'mayTrain']));
        $clean   = array_values(array_filter($vision, static fn ($m) => ! OpenRouter::mayTrain($m) && ! str_starts_with((string) $m['id'], 'openrouter/')));

        usort($clean, static fn ($a, $b) => self::price($a) <=> self::price($b));

        CLI::write(count($models) . ' models, ' . count($vision) . ' take image input, ' . count($flagged) . ' flagged as may train on data (excluded)', 'yellow');
        CLI::write('text router: ' . OpenRouter::textModel() . ' with data_collection=deny');
        CLI::write('image model now: ' . (OpenRouter::visionModel() ?: 'none'));

        if (CLI::getOption('all')) {
            foreach ($flagged as $m) {
                CLI::write('  flagged  ' . $m['id'], 'dark_gray');
            }
        }

        $limit  = max(1, (int) (CLI::getOption('probe') ?? 8));
        $picked = '';

        CLI::newLine();
        CLI::write('probing the cheapest image models with training refused:');

        foreach (array_slice($clean, 0, $limit) as $m) {
            [$ok, $text, $error] = $client->chat((string) $m['id'], [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Reply with the single word ok.'],
                    ['type' => 'image_url', 'image_url' => ['url' => self::PIXEL]],
                ],
            ]], 5, 45);

            $cost = number_format(self::price($m) * 1_000_000, 2);
            CLI::write(sprintf('  %-55s $%s per 1M in  %s', $m['id'], $cost, $ok ? 'ok' : 'no: ' . mb_substr($error, 0, 70)), $ok ? 'green' : 'red');

            if ($ok && $picked === '') {
                $picked = (string) $m['id'];
            }
        }

        if ($picked === '') {
            CLI::error('no image model passed with training refused, check credit and the account privacy settings');

            return;
        }

        CLI::newLine();
        CLI::write('best passing image model: ' . $picked, 'green');

        if (CLI::getOption('apply')) {
            Settings::set('openrouter_vision_model', $picked);
            Activity::audit('vision_model', 'image model set to ' . $picked, null, ['id' => null, 'name' => 'System']);
            CLI::write('saved as the image model', 'green');
        }
    }

    private static function price(array $m): float
    {
        return (float) ($m['pricing']['prompt'] ?? 0) + (float) ($m['pricing']['image'] ?? 0) / 1000;
    }
}
