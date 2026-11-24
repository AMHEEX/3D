<?php
/**
 * AMHEEX AI — configuração de voz (servidor).
 *
 * A chave NUNCA é enviada ao navegador. Para trocar a voz/chave no Render,
 * use as variáveis de ambiente FISH_API_KEY e FISH_VOICE_ID.
 */
declare(strict_types=1);

if (!defined('AMX_FISH_API_KEY')) {
    $__fishKey = getenv('FISH_API_KEY');
    define('AMX_FISH_API_KEY', is_string($__fishKey) && trim($__fishKey) !== ''
        ? trim($__fishKey)
        : 'sk-fish--rtXNqFaymSpaENNHyOS9YIGDYUSmwa8JFTSvLv6NoI');
}
if (!defined('AMX_FISH_VOICE_ID')) {
    $__fishVoice = getenv('FISH_VOICE_ID');
    define('AMX_FISH_VOICE_ID', is_string($__fishVoice) && trim($__fishVoice) !== ''
        ? trim($__fishVoice)
        : 'beb5077c45e141118fc52be3d2b3c9cf');
}
if (!defined('AMX_FISH_MODEL')) define('AMX_FISH_MODEL', getenv('FISH_MODEL') ?: 's2.1-pro-free');
if (!defined('AMX_FISH_URL')) define('AMX_FISH_URL', 'https://api.fish.audio/v1/tts');
