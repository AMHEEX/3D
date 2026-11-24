<?php
/**
 * AMHEEX AI — motor local de linguagem natural + gerador procedural UNIVERSAL do AMHEEX GAME.
 *
 * Não usa API externa. Lê o "cérebro" em ia/modelo.json e grava memória em ia/memoria.json.
 * Capacidades:
 *   - criar QUALQUER coisa 3D (catálogo + geração livre de formas para palavras desconhecidas)
 *   - adicionar elementos ao LAYOUT/HUD da tela (título, contador, ícone, barra, mira, relógio, painel)
 *   - criar avatares/personagens
 *   - MODIFICAR objetos existentes (cor, escala, posição, rotação, altura, brilho, transparência, metal)
 *   - montar jogos completos por preset ou "surpresa"
 *
 * Saída: array pronto para json_encode com { ok, intent, reply, fala, actions[], sugestoes[], memoria, modelo }.
 */
declare(strict_types=1);

if (!defined('AMX_IA3D')) define('AMX_IA3D', '3.0.0');
if (!defined('AMX_APP')) define('AMX_APP', 'AMHEEX AI');

/* ------------------------------------------------------------------ */
/* utilidades de arquivo                                              */
/* ------------------------------------------------------------------ */
function amx_path(string $sub = ''): string {
    $base = __DIR__;
    return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . $sub;
}

function amx_read_json(string $file, $default = []) {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return $default;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}

function amx_write_json(string $file, $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return @file_put_contents($file, $json, LOCK_EX) !== false;
}

function amx_modelo(): array {
    static $m = null;
    if ($m === null) {
        $m = amx_read_json(amx_path('modelo.json'), []);
        if (!$m) $m = ['entidades' => [], 'paleta' => [], 'respostas' => [], 'presets' => []];
    }
    return $m;
}

function amx_memoria(): array {
    $def = [
        'nome' => '', 'cor_favorita' => '', 'criados' => [], 'objetos' => [],
        'ultimo' => '', 'historico' => [], 'atualizado' => date('c')
    ];
    $m = amx_read_json(amx_path('memoria.json'), $def);
    return array_merge($def, is_array($m) ? $m : []);
}

function amx_memoria_salvar(array $mem): void {
    $mem['atualizado'] = date('c');
    if (isset($mem['historico']) && is_array($mem['historico']) && count($mem['historico']) > 300) {
        $mem['historico'] = array_slice($mem['historico'], -300);
    }
    amx_write_json(amx_path('memoria.json'), $mem);
}

/* ------------------------------------------------------------------ */
/* aprendizado local (o que o usuário ensina fica gravado)            */
/* ------------------------------------------------------------------ */
function amx_aprendizado(): array {
    $def = ['fatos' => [], 'notas' => [], 'treinos' => 0, 'atualizado' => date('c')];
    $a = amx_read_json(amx_path('aprendizado.json'), $def);
    return array_merge($def, is_array($a) ? $a : []);
}

function amx_aprendizado_salvar(array $a): void {
    $a['atualizado'] = date('c');
    if (isset($a['fatos']) && is_array($a['fatos']) && count($a['fatos']) > 500) {
        $a['fatos'] = array_slice($a['fatos'], -500, null, true);
    }
    if (isset($a['notas']) && is_array($a['notas']) && count($a['notas']) > 300) {
        $a['notas'] = array_slice($a['notas'], -300);
    }
    amx_write_json(amx_path('aprendizado.json'), $a);
}

function amx_ensinar(string $chave, string $valor): void {
    $a = amx_aprendizado();
    $k = amx_norm($chave);
    if ($k === '') $k = 'nota_' . substr(md5($valor . microtime(true)), 0, 8);
    $a['fatos'][$k] = [
        'chave' => mb_substr(trim($chave), 0, 120),
        'valor' => mb_substr(trim($valor), 0, 600),
        'quando' => date('c')
    ];
    $a['treinos'] = (int)($a['treinos'] ?? 0) + 1;
    amx_aprendizado_salvar($a);
}

function amx_buscar_fato(string $txt): ?array {
    $a = amx_aprendizado();
    $txt = amx_norm($txt);
    if (!$a['fatos']) return null;
    foreach ($a['fatos'] as $k => $f) {
        $chave = amx_norm((string)($f['chave'] ?? $k));
        if ($chave !== '' && (amx_tem($txt, $chave) || strpos($txt, $chave) !== false)) {
            return $f;
        }
    }
    return null;
}

function amx_conhecimento(string $txt): ?array {
    $kb = amx_read_json(amx_path('knowledge.json'), []);
    $areas = $kb['areas'] ?? [];
    if (!is_array($areas) || !$areas) return null;
    $melhor = null;
    $melhorScore = 0;
    foreach ($areas as $area) {
        $score = 0;
        foreach (($area['tags'] ?? []) as $tag) {
            if (amx_tem($txt, (string)$tag)) $score += 2;
        }
        foreach (preg_split('/\s+/u', amx_norm((string)($area['titulo'] ?? ''))) ?: [] as $p) {
            if (mb_strlen($p) > 3 && amx_tem($txt, $p)) $score++;
        }
        if ($score > $melhorScore) { $melhorScore = $score; $melhor = $area; }
    }
    if ($melhorScore < 2) return null;
    return $melhor;
}

/* ------------------------------------------------------------------ */
/* texto                                                              */
/* ------------------------------------------------------------------ */
function amx_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y'
    ]);
    $s = preg_replace('/[^a-z0-9#\s]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

function amx_tem(string $txt, string $termo): bool {
    $termo = amx_norm($termo);
    if ($termo === '') return false;
    return preg_match('/(?<![a-z0-9])' . preg_quote($termo, '/') . '(?![a-z0-9])/u', $txt) === 1;
}

function amx_numero(string $txt, int $def = 1): int {
    if (preg_match('/(?<![a-z0-9])(\d{1,3})(?![a-z0-9])/u', $txt, $m)) {
        $n = (int)$m[1];
        if ($n > 0) return min($n, 40);
    }
    $map = [
        'um'=>1,'uma'=>1,'dois'=>2,'duas'=>2,'tres'=>3,'quatro'=>4,'cinco'=>5,'seis'=>6,
        'sete'=>7,'oito'=>8,'nove'=>9,'dez'=>10,'onze'=>11,'doze'=>12,'treze'=>13,
        'quatorze'=>14,'catorze'=>14,'quinze'=>15,'dezesseis'=>16,'dezessete'=>17,
        'dezoito'=>18,'dezenove'=>19,'vinte'=>20,'trinta'=>30,'quarenta'=>40,'meia duzia'=>6
    ];
    foreach ($map as $w => $n) {
        if (amx_tem($txt, $w)) return $n;
    }
    return $def;
}

function amx_cor(string $txt, array $paleta): ?string {
    if (preg_match('/(#[0-9a-f]{6}|#[0-9a-f]{3})(?![0-9a-f])/i', $txt, $m)) {
        $h = strtolower($m[1]);
        if (strlen($h) === 4) $h = '#' . $h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3];
        return $h;
    }
    $chaves = array_keys($paleta);
    usort($chaves, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($chaves as $k) {
        if (amx_tem($txt, $k)) return $paleta[$k];
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* morfologia amigável (plurais em português)                         */
/* ------------------------------------------------------------------ */
function amx_formas(string $tag): array {
    $f = [$tag];
    if (preg_match('/(s|r|z)$/', $tag)) $f[] = $tag . 'es';
    if (preg_match('/l$/', $tag)) $f[] = substr($tag, 0, -1) . 'is';
    if (preg_match('/m$/', $tag)) $f[] = substr($tag, 0, -1) . 'ns';
    if (preg_match('/ao$/', $tag)) $f[] = substr($tag, 0, -2) . 'oes';
    if (preg_match('/[aeiou]$/', $tag)) $f[] = $tag . 's';
    $f[] = $tag . 's';
    $f[] = $tag . 'es';
    return array_values(array_unique($f));
}

function amx_plural(string $ent, int $n): string {
    if ($n <= 1) return $ent;
    $irr = [
        'flor'=>'flores','arvore'=>'arvores','pinheiro'=>'pinheiros','palmeira'=>'palmeiras',
        'casa'=>'casas','boneco'=>'bonecos','robo'=>'robos','cachorro'=>'cachorros','gato'=>'gatos',
        'dinossauro'=>'dinossauros','dragao'=>'dragoes','carro'=>'carros','caminhao'=>'caminhoes',
        'nave'=>'naves','montanha'=>'montanhas','lago'=>'lagos','ilha'=>'ilhas','pedra'=>'pedras',
        'castelo'=>'castelos','torre'=>'torres','ponte'=>'pontes','cerca'=>'cercas',
        'estrada'=>'estradas','tocha'=>'tochas','cristal'=>'cristais','portal'=>'portais',
        'moeda'=>'moedas','bau'=>'baus','nuvem'=>'nuvens','estrela'=>'estrelas',
        'banco'=>'bancos','poste'=>'postes','placa'=>'placas','moinho'=>'moinhos','poco'=>'pocos',
        'piramide'=>'piramides','estatua'=>'estatuas','cogumelo'=>'cogumelos','arbusto'=>'arbustos',
        'bola'=>'bolas','cubo'=>'cubos','sol'=>'sois','lua'=>'luas','predio'=>'predios',
        'edificio'=>'edificios','geladeira'=>'geladeiras','livro'=>'livros','barril'=>'barris',
        'roda'=>'rodas','diamante'=>'diamantes','parede'=>'paredes','arma'=>'armas',
        'espada'=>'espadas','escudo'=>'escudos','aviao'=>'avioes','animal'=>'animais',
        'homem'=>'homens','mulher'=>'mulheres','personagem'=>'personagens','avatar'=>'avatares',
        'guarda_roupa'=>'guarda-roupas','guarda roupa'=>'guarda-roupas'
    ];
    if (isset($irr[$ent])) return $irr[$ent];
    if (preg_match('/(s|r|z)$/', $ent)) return $ent . 'es';
    if (preg_match('/l$/', $ent)) return substr($ent, 0, -1) . 'is';
    if (preg_match('/m$/', $ent)) return substr($ent, 0, -1) . 'ns';
    if (preg_match('/ao$/', $ent)) return substr($ent, 0, -2) . 'oes';
    return $ent . 's';
}

/* ------------------------------------------------------------------ */
/* entidades e presets                                                */
/* ------------------------------------------------------------------ */
function amx_indice_entidades(array $modelo): array {
    $idx = [];
    foreach (($modelo['entidades'] ?? []) as $id => $def) {
        foreach (($def['tags'] ?? [$id]) as $tag) {
            $idx[amx_norm($tag)] = $id;
        }
        $idx[amx_norm($id)] = $id;
    }
    uksort($idx, fn($a, $b) => strlen($b) <=> strlen($a));
    return $idx;
}

function amx_entidades(string $txt, array $modelo): array {
    $achou = [];
    $txtEsp = ' ' . $txt . ' ';
    foreach (amx_indice_entidades($modelo) as $tag => $id) {
        $pos = false;
        foreach (amx_formas($tag) as $forma) {
            $p = strpos($txtEsp, ' ' . $forma . ' ');
            if ($p !== false) { $pos = $p; break; }
        }
        /* fallback por substring só para tags longas, para não confundir
           "flor" com "floresta" nem "sol" com "soldado" */
        if ($pos === false && mb_strlen($tag) >= 6) {
            $p = strpos($txt, $tag);
            if ($p !== false) {
                $depois = substr($txt, $p + strlen($tag), 1);
                if ($depois === '' || !preg_match('/[a-z0-9]/', $depois)) $pos = $p;
            }
        }
        if ($pos !== false && !isset($achou[$id])) $achou[$id] = $pos;
    }
    asort($achou);
    return array_keys($achou);
}

function amx_preset(string $txt, array $modelo): ?string {
    foreach (($modelo['presets'] ?? []) as $id => $def) {
        foreach (($def['tags'] ?? [$id]) as $tag) {
            if (amx_tem($txt, $tag)) return $id;
        }
        if (amx_tem($txt, $id)) return $id;
    }
    return null;
}

function amx_contem_acao(string $txt, string $grupo, array $modelo): bool {
    foreach (($modelo['sinonimos_acao'][$grupo] ?? []) as $w) {
        if (amx_tem($txt, $w)) return true;
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* verbos (criar / remover / modificar)                               */
/* ------------------------------------------------------------------ */
function amx_verbo(string $txt): ?string {
    $modelo = amx_modelo();
    $grupos = [
        'remover' => ['remover','remove','apague','apagar','delete','deletar','tire','tirar','exclua','excluir','destrua','destruir','limpe','limpar','suma','sumir'],
        'modificar' => ['modifique','modificar','mude','mudar','altere','alterar','transforme','transformar','deixe','deixa','torne','torna','pinte','pintar','gire','girar','suba','subir','desca','descer','posicione','posicionar','mova','mover','aumente','aumentar','diminua','diminuir'],
        'criar' => ['crie','cria','criar','faca','fazer','faça','adicione','adicionar','adiciona','coloque','colocar','cola','poe','poem','ponha','por','quero','queria','gera','gerar','gere','construa','construir','monte','montar','insira','inserir','spawn','nascer','nasça','apareça','apareca']
    ];
    foreach ($grupos as $g => $ws) {
        foreach ($ws as $w) {
            if (amx_tem($txt, $w)) return $g;
        }
    }
    /* sinônimos vindos do modelo */
    foreach (['remover','modificar','criar'] as $g) {
        if (amx_contem_acao($txt, $g, $modelo)) return $g;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* posição / altura                                                   */
/* ------------------------------------------------------------------ */
function amx_posicao(string $txt): array {
    $d = 6.0;
    if (amx_tem($txt, 'muito longe')) $d = 20.0;
    elseif (amx_tem($txt, 'longe')) $d = 13.0;
    elseif (amx_tem($txt, 'perto')) $d = 3.2;
    $x = 0.0; $z = 0.0; $rotulo = 'no centro';
    if (amx_tem($txt, 'direita')) { $x = $d; $rotulo = 'à direita'; }
    elseif (amx_tem($txt, 'esquerda')) { $x = -$d; $rotulo = 'à esquerda'; }
    elseif (amx_tem($txt, 'atras') || amx_tem($txt, 'tras') || amx_tem($txt, 'fundo')) { $z = -$d; $rotulo = 'ao fundo'; }
    elseif (amx_tem($txt, 'frente') || amx_tem($txt, 'diante')) { $z = $d; $rotulo = 'na frente'; }
    elseif (amx_tem($txt, 'centro') || amx_tem($txt, 'meio')) { $x = 0; $z = 0; $rotulo = 'no centro'; }
    elseif (amx_tem($txt, 'longe')) { $x = $d; $z = -$d; $rotulo = 'mais longe'; }
    elseif (amx_tem($txt, 'perto')) { $x = $d * 0.5; $z = $d * 0.5; $rotulo = 'perto de você'; }
    else { $x = (float)mt_rand(-30, 30) / 10; $z = (float)mt_rand(-30, 30) / 10; $rotulo = 'perto do centro'; }
    return ['x' => round($x, 2), 'z' => round($z, 2), 'rotulo' => $rotulo];
}

function amx_altura(string $txt): float {
    if (amx_tem($txt, 'no ceu') || amx_tem($txt, 'no ar') || amx_tem($txt, 'voando') || amx_tem($txt, 'flutuando') || amx_tem($txt, 'voador') || amx_tem($txt, 'voadora') || amx_tem($txt, 'voar')) return 8.0;
    if (amx_tem($txt, 'bem alto') || amx_tem($txt, 'muito alto')) return 12.0;
    if (amx_tem($txt, 'alto') || amx_tem($txt, 'subida') || amx_tem($txt, 'elevado')) return 4.0;
    if (amx_tem($txt, 'baixo') || amx_tem($txt, 'chao')) return 0.0;
    return 0.0;
}

/* ------------------------------------------------------------------ */
/* geração livre: forma geométrica para QUALQUER palavra              */
/* ------------------------------------------------------------------ */
function amx_forma(string $palavra): string {
    $p = amx_norm($palavra);
    $mapa = [
        'sphere' => ['esfera','bola','planeta','lua','sol','ovo','perola','globo','mundo','fruta','laranja','maca','melancia','cabeca','olho','balao','bolha','atomo'],
        'cylinder' => ['cilindro','cano','tubo','torre','lata','barril','tambor','roda','pneu','moeda','vela','copo','garrafa','tronco','pilar','coluna','rolo','bateria'],
        'cone' => ['cone','pinheiro','arvore','sorvete','casquinha','montanha','foguete','bico','chifre','sinal','traffic'],
        'torus' => ['anel','aro','rosca','donut','rosquinha','boia','argola','corrente','alca'],
        'pyramid' => ['piramide','triangulo','seta','telhado','obelisco'],
        'poly' => ['diamante','gema','cristal','prisma','joia','estrela','octaedro','icosaedro','dado'],
        'capsule' => ['capsula','pilula','remedio','bala','torpedo','pino','pen'],
        'plane' => ['plano','parede','tela','quadro','tapete','folha','placa','papel','cartaz','painel','porta','janela','espelho','bandeira'],
        'box' => ['caixa','cubo','bloco','predio','edificio','arranha ceu','geladeira','guarda roupa','armario','tv','televisao','monitor','livro','caderno','mesa','cama','sofa','banco','cadeira','escada','escudo','arma','espada','muro','tijolo','bau','maleta','radio','computador','teclado','caixa','container','caminhao','onibus','trem','vagao','gelo','sabao','presente','pacote','sacola','mochila','estante','prateleira','forno','microondas','maquina','motor','gerador','bateria']
    ];
    foreach ($mapa as $forma => $chaves) {
        foreach ($chaves as $k) {
            if (amx_tem($p, $k) || $p === $k) return $forma;
        }
    }
    /* determinístico: a mesma palavra gera sempre a mesma forma */
    $formas = ['box','sphere','cylinder','cone','torus','pyramid','poly','capsule'];
    $i = abs((int)crc32($p)) % count($formas);
    return $formas[$i];
}

function amx_escala_livre(string $palavra, string $txt): float {
    $s = 1.0;
    if (amx_tem($txt, 'enorme') || amx_tem($txt, 'gigante') || amx_tem($txt, 'colossal')) $s = 2.6;
    elseif (amx_tem($txt, 'grande') || amx_tem($txt, 'alto')) $s = 1.7;
    elseif (amx_tem($txt, 'pequeno') || amx_tem($txt, 'pequena') || amx_tem($txt, 'mini') || amx_tem($txt, 'minusculo')) $s = 0.55;
    elseif (mb_strlen($palavra) > 14) $s = 1.25;
    return $s;
}

function amx_stopwords(): array {
    return [
        'a','o','as','os','um','uma','uns','umas','de','do','da','dos','das','em','no','na','nos','nas',
        'para','pra','por','com','sem','e','ou','que','se','meu','minha','seu','sua','este','esta','esse','essa',
        'isso','aquilo','ai','aqui','la','ali','muito','mais','menos','bem','ja','agora','entao','tambem','so',
        'crie','cria','criar','faca','fazer','faça','adicione','adicionar','adiciona','coloque','colocar','cola',
        'poe','poem','ponha','por','quero','queria','gera','gerar','gere','construa','construir','monte','montar',
        'insira','inserir','spawn','nascer','nasça','apareça','apareca','novo','nova','novos','novas','bonito','bonita',
        'grande','pequeno','pequena','enorme','gigante','mini','minusculo','alto','baixa','baixo','colorido','colorida',
        'na','tela','layout','hud','jogo','cena','mundo','mapa','fase','level','game','tudo','coisa','objeto','elemento',
        'ai','ia','voce','vc','eu','ele','ela','nos','lhe','me','te','seu','sua','favor','favorito','agora','depois',
        'um','dois','duas','tres','quatro','cinco','seis','sete','oito','nove','dez','vinte','trinta','quarenta',
        'direita','esquerda','frente','atras','tras','fundo','centro','meio','longe','perto','ceu','ar','chao',
        'voando','flutuando','subida','elevado','modo','tipo','estilo','vezes','vez','mais','outro','outra'
    ];
}

function amx_palavra_livre(string $txt, array $modelo): ?array {
    $sw = amx_stopwords();
    $paleta = $modelo['paleta'] ?? [];
    $tokens = preg_split('/\s+/', $txt) ?: [];
    $cand = [];
    foreach ($tokens as $tk) {
        if ($tk === '' || mb_strlen($tk) < 2) continue;
        if (in_array($tk, $sw, true)) continue;
        if (preg_match('/^\d+$/', $tk)) continue;
        if (isset($paleta[$tk])) continue;
        $cand[] = $tk;
    }
    if (!$cand) return null;
    $nome = count($cand) > 3 ? implode(' ', array_slice($cand, -2)) : implode(' ', $cand);
    $nome = trim($nome);
    if ($nome === '') return null;
    return [
        'nome' => $nome,
        'chave' => str_replace(' ', '_', $nome),
        'forma' => amx_forma($nome),
        'escala' => amx_escala_livre($nome, $txt)
    ];
}

/* ------------------------------------------------------------------ */
/* resposta                                                           */
/* ------------------------------------------------------------------ */
function amx_frase(string $chave, array $vars, array $modelo): string {
    $lista = $modelo['respostas'][$chave] ?? ['Feito.'];
    if (!is_array($lista) || !$lista) $lista = ['Feito.'];
    $tpl = $lista[array_rand($lista)];
    foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    return preg_replace('/\{[a-z_]+\}/u', '', $tpl) ?? $tpl;
}

/* ------------------------------------------------------------------ */
/* LAYOUT: elementos de tela                                          */
/* ------------------------------------------------------------------ */
function amx_layout_item(string $txt): ?array {
    $mapa = [
        'titulo' => ['titulo','title','cabecalho','nome do jogo','header'],
        'texto' => ['texto','mensagem','frase','aviso','legenda','subtitulo','dica'],
        'contador' => ['contador','placar','pontos','score','pontuacao','moedas','vidas','numero'],
        'icone' => ['icone','icon','emoji','simbolo','logo','avatar da ia'],
        'barra' => ['barra','barra de vida','energia','progresso','life','hp','medidor'],
        'mira' => ['mira','crosshair','mira central','alvo','retículo','reticulo'],
        'relogio' => ['relogio','cronometro','tempo','timer','contagem'],
        'painel' => ['painel','panel','caixa de texto','janela','card','cartao'],
        'botao' => ['botao','button','tecla','comando']
    ];
    foreach ($mapa as $item => $tags) {
        foreach ($tags as $t) {
            if (amx_tem($txt, $t)) return ['item' => $item, 'tag' => $t];
        }
    }
    return null;
}

function amx_layout_pos(string $txt): string {
    if (amx_tem($txt, 'base') || amx_tem($txt, 'embaixo') || amx_tem($txt, 'rodape') || amx_tem($txt, 'baixo')) return 'base';
    if (amx_tem($txt, 'direita')) return 'dir';
    if (amx_tem($txt, 'esquerda')) return 'esq';
    if (amx_tem($txt, 'centro') || amx_tem($txt, 'meio')) return 'centro';
    return 'topo';
}

function amx_layout_texto(string $msg, string $item): string {
    /* tenta pegar o texto entre aspas */
    if (preg_match('/["“”\']([^"“”\']{2,60})["“”\']/u', $msg, $m)) return trim($m[1]);
    /* tenta "com o texto X" / "escrito X" / "dizendo X" */
    if (preg_match('/(?:com o texto|escrito|dizendo|com a frase|que diga|texto)\s+([^,.;]{2,60})/iu', $msg, $m)) {
        return trim($m[1]);
    }
    $padrao = [
        'titulo' => 'AMHEEX GAME', 'texto' => 'Bem-vindo ao AMHEEX GAME', 'contador' => '0',
        'icone' => '✦', 'barra' => '', 'mira' => '', 'relogio' => '00:00',
        'painel' => 'Painel', 'botao' => 'OK'
    ];
    return $padrao[$item] ?? '';
}

/* ------------------------------------------------------------------ */
/* MODIFICAR: propriedades                                            */
/* ------------------------------------------------------------------ */
function amx_props(string $txt, ?string $cor, array $pos): array {
    $p = [];
    if ($cor) $p['cor'] = $cor;
    if (amx_tem($txt, 'transparente') || amx_tem($txt, 'invisivel') || amx_tem($txt, 'translucido')) $p['alpha'] = 0.35;
    if (amx_tem($txt, 'brilhe') || amx_tem($txt, 'brilhar') || amx_tem($txt, 'brilhante') || amx_tem($txt, 'neon') || amx_tem($txt, 'luz') || amx_tem($txt, 'luminoso')) $p['emissivo'] = 0.9;
    if (amx_tem($txt, 'metal') || amx_tem($txt, 'metalico') || amx_tem($txt, 'cromado') || amx_tem($txt, 'espelho')) $p['metal'] = 1.0;
    if (amx_tem($txt, 'enorme') || amx_tem($txt, 'gigante') || amx_tem($txt, 'colossal')) $p['escala'] = 2.6;
    elseif (amx_tem($txt, 'grande') || amx_tem($txt, 'maior') || amx_tem($txt, 'aumente')) $p['escala'] = 1.8;
    elseif (amx_tem($txt, 'pequeno') || amx_tem($txt, 'pequena') || amx_tem($txt, 'menor') || amx_tem($txt, 'mini') || amx_tem($txt, 'diminua')) $p['escala'] = 0.5;
    if (amx_tem($txt, 'gire') || amx_tem($txt, 'girar') || amx_tem($txt, 'rotacione') || amx_tem($txt, 'vire')) $p['rotY'] = 45;
    if (amx_tem($txt, 'suba') || amx_tem($txt, 'subir') || amx_tem($txt, 'levante') || amx_tem($txt, 'flutue')) $p['y'] = 3.0;
    elseif (amx_tem($txt, 'desca') || amx_tem($txt, 'descer') || amx_tem($txt, 'abaixe')) $p['y'] = 0.0;
    if (amx_tem($txt, 'mova') || amx_tem($txt, 'mover') || amx_tem($txt, 'posicione') || amx_tem($txt, 'coloque')) {
        if (amx_tem($txt, 'direita') || amx_tem($txt, 'esquerda') || amx_tem($txt, 'frente') || amx_tem($txt, 'atras') || amx_tem($txt, 'tras') || amx_tem($txt, 'centro')) {
            $p['dx'] = $pos['x']; $p['dz'] = $pos['z'];
        }
    }
    return $p;
}

/* ------------------------------------------------------------------ */
/* NÚCLEO                                                             */
/* ------------------------------------------------------------------ */
function amx_ia_responder(string $msg, array $ctx = []): array {
    $modelo = amx_modelo();
    $paleta = $modelo['paleta'] ?? [];
    $mem = amx_memoria();
    $txt = amx_norm($msg);
    $actions = [];
    $intent = 'desconhecido';

    /* ---------- comandos compostos: "crie 3 casas e pinte a bola de azul" ---------- */
    if (empty($ctx['_sub']) && preg_match('/\s+e\s+/u', $msg)) {
        $partes = preg_split('/\s+e\s+/u', $msg);
        if (is_array($partes) && count($partes) >= 2) {
            $todasA = []; $falas = []; $ints = []; $nPartes = 0;
            $verboPrincipal = amx_verbo(amx_norm($msg));
            foreach ($partes as $parte) {
                $parte = trim($parte);
                if ($parte === '') continue;
                $vp = amx_verbo(amx_norm($parte));
                if ($vp === null) {
                    /* continuação do mesmo verbo: "crie um prédio e um avatar" */
                    if ($verboPrincipal && preg_match('/^(um|uma|o|a|os|as|mais|outro|outra|tambem|também|\d+)\b/iu', amx_norm($parte))) {
                        $parte = $verboPrincipal . ' ' . $parte;
                    } else {
                        /* adjetivo solto ("azul e brilhante") não é comando novo */
                        continue;
                    }
                }
                $nPartes++;
                $r = amx_ia_responder($parte, ['_sub' => 1]);
                if (($r['intent'] ?? 'desconhecido') === 'desconhecido') continue;
                $todasA = array_merge($todasA, $r['actions'] ?? []);
                $falas[] = $r['reply'] ?? '';
                $ints[] = $r['intent'];
            }
            if ($nPartes >= 2 && $todasA) {
                return amx_resposta_final(implode(' ', array_filter($falas)), $todasA, $modelo, amx_memoria(), implode('+', $ints));
            }
        }
    }

    $ents = amx_entidades($txt, $modelo);
    $ent = $ents[0] ?? null;
    $defEnt = $ent ? ($modelo['entidades'][$ent] ?? []) : [];
    $cor = amx_cor($txt, $paleta);
    $n = amx_numero($txt, 1);
    $pos = amx_posicao($txt);
    $verbo = amx_verbo($txt);

    /* ---------- APRENDIZADO: "aprenda que ..." / "lembre que ..." ---------- */
    if (preg_match('/(?:aprenda|aprende|lembre|lembra|guarde|memorize|anote|salve)\s+(?:que\s+)?(.{3,220})/iu', $msg, $m)) {
        $conteudo = trim($m[1]);
        $chave = $conteudo;
        $valor = $conteudo;
        if (preg_match('/^(.{2,90}?)\s+(?:e|eh|é|significa|quer dizer)\s+(.{2,180})$/iu', $conteudo, $mm)) {
            $chave = trim($mm[1]);
            $valor = trim($mm[2]);
        } elseif (strpos($conteudo, ':') !== false) {
            [$chave, $valor] = array_map('trim', explode(':', $conteudo, 2));
        }
        amx_ensinar($chave, $valor);
        return amx_resposta_final(
            'Aprendi e guardei na memória local: **' . $chave . '** = ' . $valor . '. Vou usar isso nas próximas conversas.',
            [], $modelo, $mem, 'aprender'
        );
    }
    if (amx_tem($txt, 'o que voce aprendeu') || amx_tem($txt, 'o que aprendeu') || amx_tem($txt, 'seus conhecimentos') || amx_tem($txt, 'seu treinamento')) {
        $a = amx_aprendizado();
        $n = count($a['fatos'] ?? []);
        $lista = [];
        foreach (array_slice($a['fatos'] ?? [], -8, null, true) as $f) {
            $lista[] = '• ' . ($f['chave'] ?? '') . ': ' . ($f['valor'] ?? '');
        }
        $r = 'Tenho **' . $n . ' fato(s)** aprendido(s) com você e uma base de conhecimento de jogos com 12 áreas.';
        if ($lista) $r .= "\n" . implode("\n", $lista);
        return amx_resposta_final($r, [], $modelo, $mem, 'aprendizado');
    }
    if (preg_match('/(?:esqueca|esqueça|apague|remova)\s+(?:o\s+)?(?:fato\s+)?(.{2,80})/iu', $msg, $m)) {
        $a = amx_aprendizado();
        $alvo = amx_norm($m[1]);
        $rem = 0;
        foreach ($a['fatos'] as $k => $f) {
            if (amx_tem($alvo, (string)($f['chave'] ?? $k)) || amx_tem((string)($f['chave'] ?? $k), $alvo)) {
                unset($a['fatos'][$k]); $rem++;
            }
        }
        amx_aprendizado_salvar($a);
        return amx_resposta_final($rem ? 'Removi ' . $rem . ' fato(s) da memória.' : 'Não encontrei esse fato para remover.', [], $modelo, $mem, 'esquecer');
    }

    /* ---------- CONHECIMENTO: perguntas sobre jogos, engine, física... ---------- */
    $pergunta = preg_match('/^(o que|oque|como|por que|porque|quando|onde|qual|quais|me (?:ensine|explique|de uma dica)|dica|explique|fale sobre|conte sobre)\b/u', $txt) === 1;
    if ($pergunta || amx_tem($txt, 'me ensine') || amx_tem($txt, 'como fazer') || amx_tem($txt, 'dica de')) {
        $fato = amx_buscar_fato($txt);
        if ($fato) {
            return amx_resposta_final('Sobre **' . ($fato['chave'] ?? '') . '**: ' . ($fato['valor'] ?? ''), [], $modelo, $mem, 'conhecimento_aprendido');
        }
        $area = amx_conhecimento($txt);
        if ($area) {
            return amx_resposta_final('**' . ($area['titulo'] ?? 'Conhecimento') . "**\n\n" . ($area['resposta'] ?? ''), [], $modelo, $mem, 'conhecimento');
        }
    }

    /* ---------- memória: nome e cor favorita ---------- */
    if (preg_match('/meu nome (?:e|eh) ([a-z ]{2,30})/u', $txt, $m)) {
        $nome = trim($m[1]);
        $mem['nome'] = mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
        amx_memoria_salvar($mem);
        return amx_resposta_final(amx_frase('nome', ['nome' => $mem['nome']], $modelo), [], $modelo, $mem, 'nome');
    }
    if (preg_match('/minha cor (?:favorita|preferida) (?:e|eh) ([a-z ]{3,20})/u', $txt, $m)) {
        $c = amx_cor($m[1], $paleta) ?: '#3b82f6';
        $mem['cor_favorita'] = $c;
        amx_memoria_salvar($mem);
        return amx_resposta_final(amx_frase('cor_favorita', ['cor' => $c], $modelo), [], $modelo, $mem, 'cor_favorita');
    }
    if (amx_tem($txt, 'meu nome') || amx_tem($txt, 'me chamo')) {
        $r = $mem['nome']
            ? 'Seu nome é ' . $mem['nome'] . '.'
            : 'Ainda não sei seu nome. Diga “meu nome é ...” e eu guardo na memória local.';
        return amx_resposta_final($r, [], $modelo, $mem, 'nome');
    }
    if (amx_tem($txt, 'cor favorita') || amx_tem($txt, 'cor preferida')) {
        $r = $mem['cor_favorita']
            ? 'Sua cor favorita é ' . $mem['cor_favorita'] . '.'
            : 'Você ainda não me disse sua cor favorita. Diga “minha cor favorita é azul”.';
        return amx_resposta_final($r, [], $modelo, $mem, 'cor_favorita');
    }

    /* ---------- saudação / ajuda ---------- */
    if (preg_match('/^(oi|ola|opa|eai|e ai|bom dia|boa tarde|boa noite|tudo bem|hey|hi)\b/u', $txt)) {
        $r = amx_frase('saudacao', [], $modelo);
        if ($mem['nome']) $r = $mem['nome'] . ', ' . lcfirst($r);
        return amx_resposta_final($r, [], $modelo, $mem, 'saudacao');
    }
    if (amx_tem($txt, 'ajuda') || amx_tem($txt, 'comandos') || amx_tem($txt, 'o que voce faz') || amx_tem($txt, 'help') || amx_tem($txt, 'como funciona') || amx_tem($txt, 'o que pode')) {
        return amx_resposta_final(amx_frase('ajuda', [], $modelo), [], $modelo, $mem, 'ajuda');
    }

    /* ---------- CONTROLES / CÂMERA / MODO TIRO (detectado antes do layout) ---------- */
    $querControle = amx_tem($txt, 'botao') || amx_tem($txt, 'controle') || amx_tem($txt, 'joystick')
        || amx_tem($txt, 'andar') || amx_tem($txt, 'correr') || amx_tem($txt, 'pular')
        || amx_tem($txt, 'primeira pessoa') || amx_tem($txt, 'terceira pessoa')
        || amx_tem($txt, 'modo tiro') || amx_tem($txt, 'atirar') || amx_tem($txt, 'mira');
    $controleDeJogo = $querControle && (amx_tem($txt, 'andar') || amx_tem($txt, 'correr') || amx_tem($txt, 'pular')
        || amx_tem($txt, 'controle') || amx_tem($txt, 'joystick') || amx_tem($txt, 'primeira pessoa')
        || amx_tem($txt, 'terceira pessoa') || amx_tem($txt, 'modo tiro') || amx_tem($txt, 'atirar'));

    /* ---------- LAYOUT: adicionar elemento na tela ---------- */
    $lay = amx_layout_item($txt);
    $falaTela = amx_tem($txt, 'tela') || amx_tem($txt, 'layout') || amx_tem($txt, 'hud') || amx_tem($txt, 'interface') || amx_tem($txt, 'painel');
    if ($lay && !$controleDeJogo && ($falaTela || $verbo === 'criar' || $verbo === 'modificar')) {
        $item = $lay['item'];
        $texto = amx_layout_texto($msg, $item);
        $actions[] = [
            't' => 'layout', 'item' => $item, 'texto' => $texto,
            'cor' => $cor ?: '#e8eefc', 'pos' => amx_layout_pos($txt)
        ];
        $mem['ultimo'] = 'layout:' . $item;
        amx_memoria_salvar($mem);
        return amx_resposta_final(
            amx_frase('layout', ['item' => $item, 'texto' => $texto], $modelo),
            $actions, $modelo, $mem, 'layout'
        );
    }

    /* ---------- LIMPAR TUDO ---------- */
    if ($verbo === 'remover' && (amx_tem($txt, 'tudo') || amx_tem($txt, 'cena') || amx_tem($txt, 'mundo') || amx_tem($txt, 'jogo') || amx_tem($txt, 'tela'))) {
        $actions[] = ['t' => 'clear'];
        $mem['criados'] = []; $mem['objetos'] = []; $mem['ultimo'] = '';
        amx_memoria_salvar($mem);
        return amx_resposta_final(amx_frase('limpar', [], $modelo), $actions, $modelo, $mem, 'limpar');
    }

    /* ---------- PERSONAGEM REALISTA / SKIN PROCEDURAL ---------- */
    $querRealista = amx_tem($txt, 'realista') || amx_tem($txt, 'realismo') || amx_tem($txt, 'skin') || amx_tem($txt, 'personagem') || amx_tem($txt, 'avatar') || amx_tem($txt, 'jogador');
    if ($querRealista && $verbo !== 'remover' && (amx_tem($txt, 'crie') || amx_tem($txt, 'criar') || amx_tem($txt, 'gere') || amx_tem($txt, 'personagem') || amx_tem($txt, 'avatar') || amx_tem($txt, 'skin') || amx_tem($txt, 'troque'))) {
        $nomeAv = 'AMHEEX Player';
        if (preg_match('/(?:chamad[oa]|nome|que se chama)\s+([a-z]{2,20})/u', $txt, $m)) {
            $nomeAv = mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
        }
        $pele = '#c68642';
        if (amx_tem($txt, 'clara') || amx_tem($txt, 'branca') || amx_tem($txt, 'branco')) $pele = '#f1c27d';
        elseif (amx_tem($txt, 'escura') || amx_tem($txt, 'negra') || amx_tem($txt, 'negro')) $pele = '#8d5524';
        elseif (amx_tem($txt, 'morena') || amx_tem($txt, 'moreno') || amx_tem($txt, 'parda')) $pele = '#c68642';
        $actions[] = [
            't' => 'character', 'nome' => $nomeAv, 'cor' => $cor ?: '#4f7cff',
            'pele' => $pele, 'seed' => random_int(1, 999999),
            'x' => $pos['x'], 'z' => $pos['z'], 'escala' => 1.0, 'y' => 0
        ];
        $mem['objetos']['personagem'] = $nomeAv;
        $mem['ultimo'] = 'personagem';
        amx_memoria_salvar($mem);
        return amx_resposta_final(
            'Criei o personagem **' . $nomeAv . '** com skin procedural realista: polígonos geométricos, textura de pele gerada no jogo, cabelo, olhos e roupa. Use **WASD/setas**, os botões na tela ou peça “modo tiro”.',
            $actions, $modelo, $mem, 'personagem'
        );
    }

    /* ---------- CONTROLES / CÂMERA / MODO TIRO ---------- */
    if ($querControle) {
        $mode = 'pad';
        if (amx_tem($txt, 'primeira pessoa') || amx_tem($txt, 'fps') || amx_tem($txt, 'modo tiro') || amx_tem($txt, 'atirar')) $mode = 'shooter';
        elseif (amx_tem($txt, 'terceira pessoa')) $mode = 'third';
        elseif (amx_tem($txt, 'correr')) $mode = 'run';
        elseif (amx_tem($txt, 'pular')) $mode = 'jump';
        elseif (amx_tem($txt, 'andar') || amx_tem($txt, 'botao') || amx_tem($txt, 'controle') || amx_tem($txt, 'joystick')) $mode = 'pad';
        $actions[] = ['t' => 'control', 'mode' => $mode];
        $frases = [
            'pad' => 'Adicionei o **controle na tela**: botões de andar para frente, voltar, esquerda, direita, correr e pular. Também funciona no teclado (WASD/setas).',
            'run' => 'Ativei a **corrida**: segure Shift ou o botão de correr para acelerar.',
            'jump' => 'Ativei o **pulo**: use Espaço ou o botão de pular.',
            'third' => 'Coloquei a câmera em **terceira pessoa**, acompanhando o personagem.',
            'shooter' => 'Ativei o **modo tiro**: câmera em primeira pessoa, mira central e botão de atirar. Clique/toque para disparar.'
        ];
        return amx_resposta_final($frases[$mode] ?? $frases['pad'], $actions, $modelo, $mem, 'controle');
    }

    /* ---------- MULTIPLAYER / MAPA COMPARTILHADO ---------- */
    if (amx_tem($txt, 'multiplayer') || amx_tem($txt, 'multijogador') || amx_tem($txt, 'outro dispositivo')
        || amx_tem($txt, 'outros jogadores') || amx_tem($txt, 'mapa compartilhado') || amx_tem($txt, 'jogar com amigo')
        || amx_tem($txt, 'por ip') || amx_tem($txt, 'meu ip')) {
        $presetMp = null;
        foreach (['floresta','cidade','deserto','espaco','medieval','ilha','fazenda','labirinto','corrida','plataforma'] as $pp) {
            if (amx_tem($txt, $pp)) { $presetMp = $pp; break; }
        }
        if ($presetMp) $actions[] = ['t' => 'world', 'preset' => $presetMp];
        $actions[] = ['t' => 'multiplayer', 'on' => true];
        return amx_resposta_final(
            'Ativei o **multiplayer por conta**. Cada jogador entra com usuário e senha, recebe um ID ligado à conta e aparece na lista de online sincronizando posição, skin e chat. O **mundo é único e infinito**: o mapa compartilhado fica salvo no servidor e todo mundo vê o mesmo chão verde sob o céu azul. Abra em outro celular/PC, entre com outra conta e teste.',
            $actions, $modelo, $mem, 'multiplayer'
        );
    }

    /* ---------- AVATAR / PERSONAGEM ---------- */
    $querAvatar = amx_tem($txt, 'avatar') || amx_tem($txt, 'personagem') || amx_tem($txt, 'jogador') || amx_tem($txt, 'heroi') || amx_tem($txt, 'protagonista');
    if ($querAvatar && $verbo !== 'remover') {
        $nomeAv = 'Jogador';
        if (preg_match('/(?:chamad[oa]|nome|que se chama)\s+([a-z]{2,20})/u', $txt, $m)) {
            $nomeAv = mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
        }
        $actions[] = [
            't' => 'avatar', 'nome' => $nomeAv,
            'cor' => $cor ?: ($mem['cor_favorita'] ?: '#4f7cff'),
            'x' => $pos['x'], 'z' => $pos['z'],
            'escala' => 1.0, 'y' => amx_altura($txt)
        ];
        $mem['objetos']['avatar'] = $nomeAv;
        $mem['ultimo'] = 'avatar';
        amx_memoria_salvar($mem);
        return amx_resposta_final(
            amx_frase('avatar', ['nome' => $nomeAv, 'onde' => $pos['rotulo']], $modelo),
            $actions, $modelo, $mem, 'avatar'
        );
    }

    /* ---------- REMOVER ---------- */
    if ($verbo === 'remover' && $ent) {
        $actions[] = ['t' => 'remove', 'ent' => $ent, 'all' => true];
        unset($mem['objetos'][$ent]);
        amx_memoria_salvar($mem);
        return amx_resposta_final(amx_frase('remover', ['ent' => $ent, 'n' => $n], $modelo), $actions, $modelo, $mem, 'remover');
    }

    /* ---------- CLIMA ---------- */
    $temChao = amx_tem($txt, 'chao') || amx_tem($txt, 'terreno') || amx_tem($txt, 'piso');
    $ceu = in_array($ent, ['sol', 'lua', 'nuvem', 'estrela'], true);
    $climas = [
        'chuva' => ['chuva', 'chover', 'chovendo'], 'neve' => ['neve', 'nevar', 'nevando'],
        'noite' => ['noite', 'anoitecer', 'anoiteca', 'escuro'], 'dia' => ['dia', 'amanhecer', 'clarear'],
        'por_do_sol' => ['por do sol', 'entardecer', 'sunset'], 'tempestade' => ['tempestade', 'trovao', 'relampago'],
        'nublado' => ['nublado', 'nublar'], 'neblina' => ['neblina', 'nublina'], 'sol' => ['ensolarado']
    ];
    foreach ($climas as $tipo => $tags) {
        if ($temChao || ($ceu && $verbo === 'criar')) break;
        foreach ($tags as $tag) {
            if (amx_tem($txt, $tag)) {
                $actions[] = ['t' => 'weather', 'tipo' => $tipo];
                return amx_resposta_final(amx_frase('clima', ['clima' => str_replace('_', ' ', $tipo)], $modelo), $actions, $modelo, $mem, 'clima');
            }
        }
    }

    /* ---------- TERRENO ---------- */
    $terrenos = [
        'grama' => ['grama', 'gramado', 'verde'], 'areia' => ['areia', 'deserto', 'praia'],
        'asfalto' => ['asfalto', 'pista', 'rua'], 'madeira' => ['madeira', 'tabua'],
        'neve' => ['neve'], 'agua' => ['agua'], 'lava' => ['lava', 'vulcao']
    ];
    if ($temChao) {
        foreach ($terrenos as $tipo => $tags) {
            foreach ($tags as $tag) {
                if (amx_tem($txt, $tag)) {
                    $actions[] = ['t' => 'terrain', 'tipo' => $tipo];
                    return amx_resposta_final(amx_frase('terreno', ['terreno' => $tipo], $modelo), $actions, $modelo, $mem, 'terreno');
                }
            }
        }
    }

    /* ---------- CÂMERA ---------- */
    if (amx_tem($txt, 'camera') || amx_tem($txt, 'visao') || amx_tem($txt, 'angulo')) {
        $cmd = 'reset';
        if (amx_tem($txt, 'cima') || amx_tem($txt, 'topo')) $cmd = 'topo';
        elseif (amx_tem($txt, 'aproxime') || amx_tem($txt, 'perto') || amx_tem($txt, 'zoom')) $cmd = 'aproximar';
        elseif (amx_tem($txt, 'afaste') || amx_tem($txt, 'longe')) $cmd = 'afastar';
        elseif (amx_tem($txt, 'gire') || amx_tem($txt, 'girar')) $cmd = 'girar';
        $actions[] = ['t' => 'camera', 'cmd' => $cmd];
        return amx_resposta_final(amx_frase('camera', ['cmd' => $cmd], $modelo), $actions, $modelo, $mem, 'camera');
    }

    /* ---------- FÍSICA ---------- */
    if (amx_contem_acao($txt, 'fisica', $modelo)) {
        $on = !(amx_tem($txt, 'desligue') || amx_tem($txt, 'desativa') || amx_tem($txt, 'sem'));
        $actions[] = ['t' => 'physics', 'on' => $on];
        return amx_resposta_final(amx_frase('fisica', [], $modelo), $actions, $modelo, $mem, 'fisica');
    }

    /* ---------- JOGO COMPLETO (preset) ---------- */
    $preset = amx_preset($txt, $modelo);
    $querJogo = amx_tem($txt, 'jogo') || amx_tem($txt, 'game') || amx_tem($txt, 'fase') || amx_tem($txt, 'mapa')
        || amx_tem($txt, 'mundo') || amx_tem($txt, 'cenario') || amx_tem($txt, 'level');
    if ($querJogo && ($preset || !$ent)) {
        if (!$preset) $preset = 'surpresa';
        $actions[] = ['t' => 'world', 'preset' => $preset];
        $desc = $modelo['presets'][$preset]['descricao'] ?? 'um mundo novo criado do zero';
        return amx_resposta_final(amx_frase('jogo', ['preset' => $preset, 'descricao' => $desc], $modelo), $actions, $modelo, $mem, 'jogo');
    }

    /* ---------- SKIN ---------- */
    if (amx_contem_acao($txt, 'skin', $modelo) || amx_tem($txt, 'skin')) {
        $alvo = $ent ?: 'boneco';
        $actions[] = ['t' => 'skin', 'ent' => $alvo, 'cor' => $cor ?: '#22d3ee'];
        return amx_resposta_final(amx_frase('skin', ['ent' => $alvo, 'cor' => $cor ?: '#22d3ee'], $modelo), $actions, $modelo, $mem, 'skin');
    }

    /* ---------- PINTAR ---------- */
    if ($cor && (amx_contem_acao($txt, 'pintar', $modelo) || amx_tem($txt, 'pinte') || amx_tem($txt, 'pintar') || amx_tem($txt, 'cor'))) {
        $alvo = $ent ?: ($mem['ultimo'] ?: 'bola');
        $actions[] = ['t' => 'recolor', 'ent' => $alvo, 'cor' => $cor];
        return amx_resposta_final(amx_frase('pintar', ['ent' => $alvo, 'cor' => $cor], $modelo), $actions, $modelo, $mem, 'pintar');
    }

    /* ---------- ESCALA ---------- */
    if (amx_contem_acao($txt, 'escala', $modelo) && $verbo !== 'criar') {
        $f = 1.4;
        if (amx_tem($txt, 'diminua') || amx_tem($txt, 'menor') || amx_tem($txt, 'pequeno') || amx_tem($txt, 'encolha')) $f = 0.65;
        if (amx_tem($txt, 'gigante') || amx_tem($txt, 'enorme')) $f = 2.2;
        $alvo = $ent ?: ($mem['ultimo'] ?: 'bola');
        $actions[] = ['t' => 'scale', 'ent' => $alvo, 'f' => $f];
        return amx_resposta_final(amx_frase('escala', ['ent' => $alvo, 'escala' => ($f > 1 ? 'maior' : 'menor')], $modelo), $actions, $modelo, $mem, 'escala');
    }

    /* ---------- MOVER / GIRAR ---------- */
    if (amx_contem_acao($txt, 'mover', $modelo) && $ent) {
        $actions[] = ['t' => 'move', 'ent' => $ent, 'dx' => $pos['x'], 'dz' => $pos['z']];
        return amx_resposta_final(amx_frase('mover', ['ent' => $ent, 'onde' => $pos['rotulo']], $modelo), $actions, $modelo, $mem, 'mover');
    }
    if (amx_contem_acao($txt, 'girar', $modelo)) {
        $alvo = $ent ?: ($mem['ultimo'] ?: 'bola');
        $actions[] = ['t' => 'rotate', 'ent' => $alvo, 'deg' => 45];
        return amx_resposta_final('Girei ' . $alvo . ' em 45 graus.', $actions, $modelo, $mem, 'girar');
    }

    /* ---------- MODIFICAR (objeto existente) ---------- */
    if ($verbo === 'modificar' && $ent) {
        $props = amx_props($txt, $cor, $pos);
        if ($props) {
            $actions[] = ['t' => 'modify', 'ent' => $ent, 'props' => $props];
            $mem['ultimo'] = $ent;
            amx_memoria_salvar($mem);
            $lista = [];
            if (isset($props['cor'])) $lista[] = 'cor ' . $props['cor'];
            if (isset($props['escala'])) $lista[] = 'escala ' . $props['escala'];
            if (isset($props['alpha'])) $lista[] = 'transparência';
            if (isset($props['emissivo'])) $lista[] = 'brilho';
            if (isset($props['metal'])) $lista[] = 'metal';
            if (isset($props['rotY'])) $lista[] = 'rotação';
            if (isset($props['y'])) $lista[] = 'altura';
            if (isset($props['dx'])) $lista[] = 'posição';
            return amx_resposta_final(
                amx_frase('modificar', ['ent' => $ent, 'props' => implode(', ', $lista)], $modelo),
                $actions, $modelo, $mem, 'modificar'
            );
        }
    }

    /* ---------- CRIAR do catálogo ---------- */
    $querCriar = $verbo === 'criar' || $ent !== null;
    if ($querCriar && $ent) {
        $n = max(1, min($n, 24));
        $corFinal = $cor ?: ($mem['cor_favorita'] ?: ($defEnt['cor'] ?? '#3b82f6'));
        $escala = (float)($defEnt['escala'] ?? 1.0);
        if (amx_tem($txt, 'enorme') || amx_tem($txt, 'gigante') || amx_tem($txt, 'colossal')) $escala *= 2.2;
        elseif (amx_tem($txt, 'grande') || amx_tem($txt, 'alto')) $escala *= 1.6;
        elseif (amx_tem($txt, 'pequeno') || amx_tem($txt, 'pequena') || amx_tem($txt, 'mini')) $escala *= 0.6;
        $actions[] = [
            't' => 'spawn', 'ent' => $ent, 'n' => $n, 'cor' => $corFinal,
            'x' => $pos['x'], 'z' => $pos['z'], 'escala' => $escala, 'y' => amx_altura($txt)
        ];
        $mem['criados'][$ent] = (int)($mem['criados'][$ent] ?? 0) + $n;
        $mem['objetos'][$ent] = ($mem['objetos'][$ent] ?? 0) + $n;
        $mem['ultimo'] = $ent;
        amx_memoria_salvar($mem);
        return amx_resposta_final(
            amx_frase('criar', ['n' => $n, 'ent' => amx_plural($ent, $n), 'onde' => $pos['rotulo'], 'cor' => $corFinal], $modelo),
            $actions, $modelo, $mem, 'criar'
        );
    }

    /* ---------- CRIAR LIVRE (qualquer coisa 3D) ---------- */
    if ($verbo === 'criar' || $verbo === null) {
        $livre = amx_palavra_livre($txt, $modelo);
        if ($livre) {
            $n = max(1, min($n, 24));
            $corFinal = $cor ?: ($mem['cor_favorita'] ?: '#7dd3fc');
            $actions[] = [
                't' => 'spawn', 'ent' => 'livre', 'nome' => $livre['nome'], 'forma' => $livre['forma'],
                'n' => $n, 'cor' => $corFinal, 'x' => $pos['x'], 'z' => $pos['z'],
                'escala' => $livre['escala'], 'y' => amx_altura($txt)
            ];
            $mem['criados'][$livre['chave']] = (int)($mem['criados'][$livre['chave']] ?? 0) + $n;
            $mem['objetos'][$livre['chave']] = ($mem['objetos'][$livre['chave']] ?? 0) + $n;
            $mem['ultimo'] = $livre['chave'];
            amx_memoria_salvar($mem);
            return amx_resposta_final(
                amx_frase('criar_livre', ['n' => $n, 'nome' => amx_plural($livre['nome'], $n), 'forma' => $livre['forma'], 'onde' => $pos['rotulo'], 'cor' => $corFinal], $modelo),
                $actions, $modelo, $mem, 'criar_livre'
            );
        }
    }

    /* ---------- fallback ---------- */
    return amx_resposta_final(amx_frase('desconhecido', [], $modelo), [], $modelo, $mem, 'desconhecido');
}

function amx_resposta_final(string $resposta, array $actions, array $modelo, array $mem, string $intent): array {
    return [
        'ok' => true,
        'intent' => $intent,
        'reply' => $resposta,
        'fala' => $resposta,
        'actions' => $actions,
        'sugestoes' => $modelo['sugestoes'] ?? [],
        'memoria' => [
            'nome' => $mem['nome'] ?? '',
            'cor_favorita' => $mem['cor_favorita'] ?? '',
            'criados' => $mem['criados'] ?? [],
            'objetos' => $mem['objetos'] ?? [],
            'ultimo' => $mem['ultimo'] ?? ''
        ],
        'modelo' => [
            'nome' => $modelo['nome'] ?? AMX_APP,
            'versao' => $modelo['versao'] ?? AMX_IA3D,
            'motor' => $modelo['motor'] ?? 'local'
        ]
    ];
}

/* ------------------------------------------------------------------ */
/* histórico                                                          */
/* ------------------------------------------------------------------ */
function amx_historico_add(string $quem, string $texto): void {
    $mem = amx_memoria();
    $mem['historico'][] = ['q' => $quem, 't' => mb_substr($texto, 0, 2000), 'h' => date('c')];
    amx_memoria_salvar($mem);
}
