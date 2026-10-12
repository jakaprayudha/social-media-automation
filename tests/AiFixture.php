<?php
declare(strict_types=1);

final class AiFixture implements AiTextProvider
{
    public int $calls = 0;

    public function __construct(private readonly ?string $failure = null, private readonly bool $emptyMedia = false)
    {
    }

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    public function generate(array $draft): array
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw new AiFailure($this->failure);
        }
        $variants = [];
        foreach ($draft['platforms'] as $platform) {
            $variant = array_fill_keys(array_keys(AiStudio::OUTPUT_FIELDS), '');
            $variant = array_replace($variant, [
                'platform' => $platform, 'title' => 'Fixture ' . $platform,
                'body' => '<script>Fixture article</script>', 'caption' => 'Fixture caption ' . $platform,
                'hook' => 'Fixture hook', 'script' => 'Fixture scene and narration',
                'cta' => 'Fixture CTA', 'hashtags' => '#fixture', 'alt_text' => 'Fixture alt text',
                'image_brief' => 'Fixture visual composition', 'video_brief' => 'Fixture storyboard',
                'audio_script' => 'Fixture audio narration', 'checks' => ['Periksa fakta dan lisensi media.'],
            ]);
            if ($this->emptyMedia) {
                $variant['image_brief'] = '';
                $variant['video_brief'] = '';
                $variant['audio_script'] = '';
            }
            $variants[] = $variant;
        }
        return ['variants' => $variants];
    }
}
