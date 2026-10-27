<?php
// MEDIA Timeline: komandanın video layihələri. Brauzer redaktoru (Edit Media → 🎞 Timeline) və Claude (planet-media MCP)
// eyni timeline JSON-u redaktə edir. Format: system/MEDIA_TIMELINE.md. media.php daxil edir ($pdo, $me, $name, $in, $act, $now hazırdır).
//  project_list / project_get / project_create / project_rename / project_delete
//  project_save   : bütün timeline (brauzer). base_version köhnədirsə 409 + son versiya
//  project_apply  : redaktə əməliyyatları siyahısı (Claude və AI qutusu): add_track, remove_track, add_clip, add_text, add_captions,
//                   move, trim, split, delete, set_props, set_canvas. Hamısı yoxlanır, bir versiya kimi saxlanır
//  project_versions / project_restore : son 50 versiya (geri qaytarma)
//  asset_list : istifadəçinin yükləmələri, renderləri, hazır fal generasiyaları və layihəyə bağlı aktivlər
//  asset_delete : öz aktivini sil (fayl da silinir)
//  upload_render : MP4 render (brauzer eksportu və ya Claude/HyperFrames) → layihənin eksportları
defined('MEDIA_TL') || define('MEDIA_TL', 1);
if (!isset($pdo, $act)) {
    exit;
}

const TL_KINDS = ['video' => ['video', 'image'], 'audio' => ['audio'], 'text' => ['text'], 'caption' => ['caption']];
const TL_MAX_TRACKS = 16;
const TL_MAX_CLIPS = 600;
const TL_MAX_DUR = 3600.0;
const TL_PRESETS = ['landscape' => [1920, 1080], 'reels' => [1080, 1920], 'square' => [1080, 1080], 'portrait' => [1080, 1350]];

function tl_num($v, float $def, float $min, float $max): float
{
    if (!is_numeric($v)) {
        return $def;
    }
    $v = (float)$v;
    return is_finite($v) ? round(min($max, max($min, $v)), 4) : $def;
}
function tl_str($v, int $max): string
{
    return mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_scalar($v) ? (string)$v : '')), 0, $max);
}
function tl_color($v, string $def): string
{
    $v = is_string($v) ? trim($v) : '';
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v) || preg_match('/^rgba?\([\d\s.,%]+\)$/i', $v) || $v === 'transparent' ? $v : $def;
}
function tl_url($v): string
{
    $v = is_string($v) ? trim($v) : '';
    return preg_match('~^https?://[^\s"\'<>\\\\]{3,400}$~i', $v) ? $v : '';
}
function tl_id(string $p, array &$used): string
{
    do {
        $id = $p . substr(bin2hex(random_bytes(4)), 0, 6);
    } while (isset($used[$id]));
    $used[$id] = 1;
    return $id;
}

function tl_empty(int $w = 1920, int $h = 1080): array
{
    return ['v' => 1, 'width' => $w, 'height' => $h, 'fps' => 30, 'background' => '#000000', 'duration' => 0,
        'tracks' => [['id' => 't_video1', 'kind' => 'video', 'name' => 'Video 1', 'muted' => false, 'hidden' => false, 'clips' => []],
            ['id' => 't_text1', 'kind' => 'text', 'name' => 'Mətn', 'muted' => false, 'hidden' => false, 'clips' => []],
            ['id' => 't_audio1', 'kind' => 'audio', 'name' => 'Səs', 'muted' => false, 'hidden' => false, 'clips' => []]]];
}

/** Bir klipi yoxlayır/təmizləyir (növ trekə uyğun olmalıdır) */
function tl_clip(array $c, string $kind, array &$used): array
{
    $type = (string)($c['type'] ?? TL_KINDS[$kind][0]);
    if (!in_array($type, TL_KINDS[$kind], true)) {
        throw new InvalidArgumentException("'$type' klipi '$kind' trekinə qoyula bilməz (" . implode('/', TL_KINDS[$kind]) . ' lazımdır)');
    }
    $id = is_string($c['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $c['id']) && !isset($used[$c['id']]) ? $c['id'] : tl_id('c_', $used);
    $used[$id] = 1;
    $o = ['id' => $id, 'type' => $type, 'start' => tl_num($c['start'] ?? 0, 0, 0, TL_MAX_DUR), 'dur' => tl_num($c['dur'] ?? 3, 3, 0.04, TL_MAX_DUR)];
    $name = tl_str($c['name'] ?? '', 120);
    if ($name !== '') {
        $o['name'] = $name;
    }
    if (in_array($type, ['video', 'image', 'audio'], true)) {
        $o['src'] = tl_url($c['src'] ?? '');
        if ($o['src'] === '') {
            throw new InvalidArgumentException("'$type' klipi üçün src (https URL) lazımdır");
        }
        if ($type !== 'image') {
            $o['in'] = tl_num($c['in'] ?? 0, 0, 0, TL_MAX_DUR);
            $o['speed'] = tl_num($c['speed'] ?? 1, 1, 0.25, 4);
            $o['volume'] = tl_num($c['volume'] ?? 1, 1, 0, 2);
            if (isset($c['srcDur'])) {
                $o['srcDur'] = tl_num($c['srcDur'], 0, 0, TL_MAX_DUR);
            }
        }
    }
    if ($type !== 'audio') {                                           // görünən klipin yeri və görünüşü
        $o['x'] = tl_num($c['x'] ?? 0.5, 0.5, -1, 2);                   // mərkəz, kadra nisbətən (0..1)
        $o['y'] = tl_num($c['y'] ?? ($type === 'caption' ? 0.86 : 0.5), 0.5, -1, 2);
        $o['scale'] = tl_num($c['scale'] ?? 1, 1, 0.05, 10);
        $o['rotation'] = tl_num($c['rotation'] ?? 0, 0, -360, 360);
        $o['opacity'] = tl_num($c['opacity'] ?? 1, 1, 0, 1);
    }
    if ($type === 'video' || $type === 'image') {
        $o['fit'] = in_array($c['fit'] ?? 'cover', ['cover', 'contain', 'fill'], true) ? ($c['fit'] ?? 'cover') : 'cover';
    }
    $o['fadeIn'] = tl_num($c['fadeIn'] ?? 0, 0, 0, 30);
    $o['fadeOut'] = tl_num($c['fadeOut'] ?? 0, 0, 0, 30);
    if ($type === 'text' || $type === 'caption') {
        $o['text'] = tl_str($c['text'] ?? '', 1000);
        if ($o['text'] === '') {
            throw new InvalidArgumentException('Mətn klipi boş ola bilməz');
        }
        $s = is_array($c['style'] ?? null) ? $c['style'] : [];
        $o['style'] = [
            'font' => tl_str($s['font'] ?? 'Inter', 60) ?: 'Inter',
            'size' => tl_num($s['size'] ?? ($type === 'caption' ? 54 : 96), 64, 8, 600),
            'weight' => (int)tl_num($s['weight'] ?? 700, 700, 100, 900),
            'color' => tl_color($s['color'] ?? '#ffffff', '#ffffff'),
            'bg' => tl_color($s['bg'] ?? ($type === 'caption' ? 'rgba(0,0,0,0.55)' : 'transparent'), 'transparent'),
            'stroke' => tl_color($s['stroke'] ?? 'transparent', 'transparent'),
            'align' => in_array($s['align'] ?? 'center', ['left', 'center', 'right'], true) ? ($s['align'] ?? 'center') : 'center',
            'italic' => !empty($s['italic']),
            'anim' => in_array($s['anim'] ?? 'none', ['none', 'fade', 'pop', 'slide-up', 'typewriter'], true) ? ($s['anim'] ?? 'none') : 'none',
        ];
    }
    return $o;
}

/** Bütün timeline-ı yoxlayır/təmizləyir: trek/klip limitləri, id-lər, müddət */
function tl_normalize($t): array
{
    if (!is_array($t)) {
        throw new InvalidArgumentException('timeline obyekt olmalıdır');
    }
    $used = [];
    $o = ['v' => 1, 'width' => (int)tl_num($t['width'] ?? 1920, 1920, 64, 4096), 'height' => (int)tl_num($t['height'] ?? 1080, 1080, 64, 4096),
        'fps' => (int)tl_num($t['fps'] ?? 30, 30, 1, 60), 'background' => tl_color($t['background'] ?? '#000000', '#000000'), 'tracks' => []];
    $o['width'] -= $o['width'] % 2;                                     // H.264 cüt ölçü istəyir
    $o['height'] -= $o['height'] % 2;
    $tracks = is_array($t['tracks'] ?? null) ? array_values($t['tracks']) : [];
    if (count($tracks) > TL_MAX_TRACKS) {
        throw new InvalidArgumentException('Ən çox ' . TL_MAX_TRACKS . ' trek ola bilər');
    }
    $n = 0;
    $end = 0.0;
    foreach ($tracks as $tr) {
        if (!is_array($tr)) {
            continue;
        }
        $kind = (string)($tr['kind'] ?? 'video');
        if (!isset(TL_KINDS[$kind])) {
            throw new InvalidArgumentException("Naməlum trek növü: $kind (video, audio, text, caption)");
        }
        $tid = is_string($tr['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tr['id']) && !isset($used[$tr['id']]) ? $tr['id'] : tl_id('t_', $used);
        $used[$tid] = 1;
        $clips = [];
        foreach (is_array($tr['clips'] ?? null) ? $tr['clips'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (++$n > TL_MAX_CLIPS) {
                throw new InvalidArgumentException('Ən çox ' . TL_MAX_CLIPS . ' klip ola bilər');
            }
            $c = tl_clip($c, $kind, $used);
            $end = max($end, $c['start'] + $c['dur']);
            $clips[] = $c;
        }
        usort($clips, fn($a, $b) => $a['start'] <=> $b['start']);
        $o['tracks'][] = ['id' => $tid, 'kind' => $kind, 'name' => tl_str($tr['name'] ?? '', 60) ?: ucfirst($kind),
            'muted' => !empty($tr['muted']), 'hidden' => !empty($tr['hidden']), 'clips' => $clips];
    }
    $o['duration'] = round($end, 3);
    return $o;
}

/** id-yə görə klip: [trek indeksi, klip indeksi] */
function tl_find(array $t, string $id): array
{
    foreach ($t['tracks'] as $ti => $tr) {
        foreach ($tr['clips'] as $ci => $c) {
            if ($c['id'] === $id) {
                return [$ti, $ci];
            }
        }
    }
    throw new InvalidArgumentException("Klip tapılmadı: $id");
}
function tl_track(array &$t, $ref, string $kind, bool $create = true): int
{
    if (is_string($ref) && $ref !== '') {
        foreach ($t['tracks'] as $i => $tr) {
            if ($tr['id'] === $ref || mb_strtolower($tr['name']) === mb_strtolower($ref)) {
                if ($tr['kind'] !== $kind) {
                    throw new InvalidArgumentException("Trek '$ref' növü {$tr['kind']}-dir, $kind lazımdır");
                }
                return $i;
            }
        }
        throw new InvalidArgumentException("Trek tapılmadı: $ref");
    }
    foreach ($t['tracks'] as $i => $tr) {
        if ($tr['kind'] === $kind) {
            return $i;
        }
    }
    if (!$create) {
        throw new InvalidArgumentException("$kind treki yoxdur");
    }
    $t['tracks'][] = ['id' => 't_' . substr(bin2hex(random_bytes(4)), 0, 6), 'kind' => $kind, 'name' => ucfirst($kind), 'muted' => false, 'hidden' => false, 'clips' => []];
    return count($t['tracks']) - 1;
}
function tl_track_end(array $tr): float
{
    $e = 0.0;
    foreach ($tr['clips'] as $c) {
        $e = max($e, $c['start'] + $c['dur']);
    }
    return $e;
}

/** Redaktə əməliyyatlarını tətbiq edir. Qaytarır: [yeni timeline, yaradılmış id-lər] */
function tl_apply(array $t, array $ops): array
{
    $made = [];
    $clipProps = ['start', 'dur', 'in', 'speed', 'volume', 'x', 'y', 'scale', 'rotation', 'opacity', 'fit', 'fadeIn', 'fadeOut', 'text', 'style', 'src', 'name', 'srcDur'];
    foreach (array_values($ops) as $i => $op) {
        try {
            if (!is_array($op) || !is_string($op['op'] ?? null)) {
                throw new InvalidArgumentException('hər əməliyyatın "op" sahəsi olmalıdır');
            }
            switch ($op['op']) {
                case 'add_track':
                    $kind = (string)($op['kind'] ?? 'video');
                    if (!isset(TL_KINDS[$kind])) {
                        throw new InvalidArgumentException("Naməlum trek növü: $kind");
                    }
                    $id = 't_' . substr(bin2hex(random_bytes(4)), 0, 6);
                    $tr = ['id' => $id, 'kind' => $kind, 'name' => tl_str($op['name'] ?? '', 60) ?: ucfirst($kind), 'muted' => false, 'hidden' => false, 'clips' => []];
                    if (!empty($op['below'])) {
                        array_unshift($t['tracks'], $tr);
                    } else {
                        $t['tracks'][] = $tr;
                    }
                    $made[] = $id;
                    break;
                case 'remove_track':
                    $ti = null;
                    foreach ($t['tracks'] as $k => $tr) {
                        if ($tr['id'] === ($op['track'] ?? '')) {
                            $ti = $k;
                        }
                    }
                    if ($ti === null) {
                        throw new InvalidArgumentException('Trek tapılmadı: ' . ($op['track'] ?? ''));
                    }
                    array_splice($t['tracks'], $ti, 1);
                    break;
                case 'add_clip':
                case 'add_text':
                    $type = $op['op'] === 'add_text' ? 'text' : (string)($op['type'] ?? '');
                    if ($type === '' && is_string($op['src'] ?? null)) {
                        $type = preg_match('~\.(mp3|wav|m4a|ogg)(\?|$)|f=[a-f0-9]{32}\.(mp3|wav|m4a|ogg)~i', $op['src']) ? 'audio'
                            : (preg_match('~\.(mp4|webm|mov)(\?|$)|f=[a-f0-9]{32}\.(mp4|webm|mov)~i', $op['src']) ? 'video' : 'image');
                    }
                    $kind = $type === 'image' ? 'video' : $type;
                    if (!isset(TL_KINDS[$kind])) {
                        throw new InvalidArgumentException("Naməlum klip növü: $type (video, image, audio, text)");
                    }
                    $ti = tl_track($t, $op['track'] ?? null, $kind);
                    $c = array_intersect_key($op, array_flip($clipProps));
                    $c['type'] = $type;
                    if (!isset($op['start'])) {
                        $c['start'] = tl_track_end($t['tracks'][$ti]);           // trekin sonuna
                    }
                    $used = [];
                    foreach ($t['tracks'] as $tr) {
                        foreach ($tr['clips'] as $x) {
                            $used[$x['id']] = 1;
                        }
                    }
                    $c = tl_clip($c, $kind, $used);
                    $t['tracks'][$ti]['clips'][] = $c;
                    $made[] = $c['id'];
                    break;
                case 'add_captions':
                    $ti = tl_track($t, $op['track'] ?? null, 'caption');
                    if (!empty($op['replace'])) {
                        $t['tracks'][$ti]['clips'] = [];
                    }
                    $style = is_array($op['style'] ?? null) ? $op['style'] : [];
                    $used = [];
                    foreach ($t['tracks'] as $tr) {
                        foreach ($tr['clips'] as $x) {
                            $used[$x['id']] = 1;
                        }
                    }
                    $cues = is_array($op['cues'] ?? null) ? array_slice($op['cues'], 0, 500) : [];
                    if (!$cues) {
                        throw new InvalidArgumentException('cues boşdur: [{start, end, text}]');
                    }
                    foreach ($cues as $q) {
                        $s = tl_num($q['start'] ?? 0, 0, 0, TL_MAX_DUR);
                        $e = tl_num($q['end'] ?? $s + 2, $s + 2, $s + 0.05, TL_MAX_DUR);
                        $c = tl_clip(['type' => 'caption', 'start' => $s, 'dur' => $e - $s, 'text' => $q['text'] ?? '', 'style' => $style], 'caption', $used);
                        $t['tracks'][$ti]['clips'][] = $c;
                        $made[] = $c['id'];
                    }
                    break;
                case 'move':
                case 'trim':
                case 'set_props':
                    [$ti, $ci] = tl_find($t, (string)($op['clip'] ?? ''));
                    $c = $t['tracks'][$ti]['clips'][$ci];
                    $p = $op['op'] === 'set_props' ? (is_array($op['props'] ?? null) ? $op['props'] : []) : $op;
                    if ($op['op'] === 'trim' && isset($op['end']) && !isset($op['dur'])) {
                        $p['dur'] = (float)$op['end'] - (float)($op['start'] ?? $c['start']);
                    }
                    if (isset($p['style'], $c['style']) && is_array($p['style'])) {
                        $p['style'] = $p['style'] + $c['style'];
                    }
                    $c = array_merge($c, array_intersect_key($p, array_flip($clipProps)));
                    if ($op['op'] === 'move' && is_string($op['track'] ?? null) && $op['track'] !== $t['tracks'][$ti]['id']) {
                        $kind = $c['type'] === 'image' ? 'video' : $c['type'];
                        $nt = tl_track($t, $op['track'], $kind, false);
                        array_splice($t['tracks'][$ti]['clips'], $ci, 1);
                        $t['tracks'][$nt]['clips'][] = $c;
                    } else {
                        $t['tracks'][$ti]['clips'][$ci] = $c;
                    }
                    break;
                case 'split':
                    [$ti, $ci] = tl_find($t, (string)($op['clip'] ?? ''));
                    $c = $t['tracks'][$ti]['clips'][$ci];
                    $at = (float)($op['at'] ?? -1);
                    if ($at <= $c['start'] + 0.04 || $at >= $c['start'] + $c['dur'] - 0.04) {
                        throw new InvalidArgumentException('split: "at" klipin içində olmalıdır (' . $c['start'] . ' – ' . ($c['start'] + $c['dur']) . ')');
                    }
                    $a = $c;
                    $b = $c;
                    $a['dur'] = round($at - $c['start'], 4);
                    $a['fadeOut'] = 0;
                    $b['id'] = 'c_' . substr(bin2hex(random_bytes(4)), 0, 6);
                    $b['start'] = round($at, 4);
                    $b['dur'] = round($c['dur'] - $a['dur'], 4);
                    $b['fadeIn'] = 0;
                    if (isset($c['in'])) {
                        $b['in'] = round($c['in'] + $a['dur'] * ($c['speed'] ?? 1), 4);
                    }
                    $t['tracks'][$ti]['clips'][$ci] = $a;
                    $t['tracks'][$ti]['clips'][] = $b;
                    $made[] = $b['id'];
                    break;
                case 'delete':
                    [$ti, $ci] = tl_find($t, (string)($op['clip'] ?? ''));
                    $gone = $t['tracks'][$ti]['clips'][$ci];
                    array_splice($t['tracks'][$ti]['clips'], $ci, 1);
                    if (!empty($op['ripple'])) {                               // eyni trekdə arxadakıları boşluq qədər sola çək
                        foreach ($t['tracks'][$ti]['clips'] as $k => $x) {
                            if ($x['start'] >= $gone['start'] + $gone['dur'] - 0.001) {
                                $t['tracks'][$ti]['clips'][$k]['start'] = round(max(0, $x['start'] - $gone['dur']), 4);
                            }
                        }
                    }
                    break;
                case 'set_track':
                    $found = false;
                    foreach ($t['tracks'] as $k => $tr) {
                        if ($tr['id'] === ($op['track'] ?? '')) {
                            foreach (['name', 'muted', 'hidden'] as $f) {
                                if (array_key_exists($f, $op)) {
                                    $t['tracks'][$k][$f] = $op[$f];
                                }
                            }
                            $found = true;
                        }
                    }
                    if (!$found) {
                        throw new InvalidArgumentException('Trek tapılmadı: ' . ($op['track'] ?? ''));
                    }
                    break;
                case 'set_canvas':
                    if (isset($op['preset'])) {
                        if (!isset(TL_PRESETS[$op['preset']])) {
                            throw new InvalidArgumentException('preset: ' . implode(', ', array_keys(TL_PRESETS)));
                        }
                        [$t['width'], $t['height']] = TL_PRESETS[$op['preset']];
                    }
                    foreach (['width', 'height', 'fps', 'background'] as $f) {
                        if (isset($op[$f])) {
                            $t[$f] = $op[$f];
                        }
                    }
                    break;
                default:
                    throw new InvalidArgumentException('Naməlum əməliyyat: ' . $op['op']);
            }
            $t = tl_normalize($t);                                            // hər addımdan sonra yoxla
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Əməliyyat #' . ($i + 1) . ' (' . (is_array($op) ? ($op['op'] ?? '?') : '?') . '): ' . $e->getMessage());
        }
    }
    return [$t, $made];
}

function tl_project(PDO $pdo, $id): array
{
    $st = $pdo->prepare('SELECT * FROM media_projects WHERE id = ?');
    $st->execute([(int)$id]);
    $p = $st->fetch();
    if (!$p) {
        json_out(['error' => 'Layihə tapılmadı'], 404);
    }
    return $p;
}
function tl_out(array $p, bool $full = true): array
{
    $t = json_decode((string)$p['timeline'], true) ?: tl_empty();
    $o = ['id' => (int)$p['id'], 'title' => $p['title'], 'owner' => $p['owner'], 'version' => (int)$p['version'], 'updated_by' => $p['updated_by'],
        'updated_at' => (int)$p['updated_at'], 'created_at' => (int)$p['created_at'], 'duration' => (float)($t['duration'] ?? 0),
        'width' => (int)($t['width'] ?? 0), 'height' => (int)($t['height'] ?? 0)];
    if ($full) {
        $o['timeline'] = $t;
    } else {
        $o['clips'] = array_sum(array_map(fn($tr) => count($tr['clips'] ?? []), $t['tracks'] ?? []));
        foreach ($t['tracks'] ?? [] as $tr) {                                // kiçik şəkil: ilk görünən media
            foreach ($tr['clips'] ?? [] as $c) {
                if (in_array($c['type'], ['video', 'image'], true) && empty($o['thumb'])) {
                    $o['thumb'] = ['url' => $c['src'], 'type' => $c['type']];
                }
            }
        }
    }
    return $o;
}
/** Yeni versiyanı yazır (son 50 saxlanır) və brauzerlərə canlı xəbər verir */
function tl_commit(PDO $pdo, array $p, array $t, string $who, string $note, int $now): array
{
    $v = (int)$p['version'] + 1;
    $j = json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $u = $pdo->prepare('UPDATE media_projects SET timeline = ?, version = ?, updated_by = ?, updated_at = ? WHERE id = ? AND version = ?');
    $u->execute([$j, $v, $who, $now, $p['id'], $p['version']]);
    if ($u->rowCount() === 0) {                                              // eyni anda başqası saxladı
        json_out(['error' => 'Layihə bu arada dəyişdi, yenidən yüklə', 'conflict' => true, 'project' => tl_out(tl_project($pdo, $p['id']))], 409);
    }
    $pdo->prepare('INSERT INTO media_project_versions (project_id, version, timeline, author, note, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$p['id'], $v, $j, $who, mb_substr($note, 0, 200), $now]);
    $pdo->prepare('DELETE FROM media_project_versions WHERE project_id = ? AND version <= ?')->execute([$p['id'], $v - 50]);
    emit_event($pdo, 'media.project', '', '', (int)$p['id'], ['version' => $v, 'by' => $who, 'note' => mb_substr($note, 0, 120), 'title' => $p['title']]);
    $p['timeline'] = $j;
    $p['version'] = $v;
    $p['updated_by'] = $who;
    $p['updated_at'] = $now;
    return $p;
}
function tl_base_ok(array $p, array $in): void
{
    if (isset($in['base_version']) && (int)$in['base_version'] !== (int)$p['version']) {
        json_out(['error' => 'Layihə bu arada dəyişdi (sənin versiyan ' . (int)$in['base_version'] . ', son ' . $p['version'] . '). Yenidən yüklə və təkrar et.',
            'conflict' => true, 'project' => tl_out($p)], 409);
    }
}
$isAdmin = ($me['role'] ?? '') === 'admin';

if ($act === 'project_list') {
    $rows = $pdo->query('SELECT * FROM media_projects ORDER BY updated_at DESC LIMIT 100')->fetchAll();
    json_out(['projects' => array_map(fn($p) => tl_out($p, false), $rows)]);
}
if ($act === 'project_get') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    $ex = $pdo->prepare("SELECT id, url, type, name, size, user, created_at FROM media_assets WHERE project_id = ? AND kind = 'export' ORDER BY id DESC LIMIT 20");
    $ex->execute([$p['id']]);
    json_out(['project' => tl_out($p), 'exports' => $ex->fetchAll()]);
}
if ($act === 'project_create') {
    $title = tl_str($in['title'] ?? '', 160) ?: 'Yeni video';
    [$w, $h] = TL_PRESETS[(string)($in['preset'] ?? 'landscape')] ?? TL_PRESETS['landscape'];
    $rl = $pdo->prepare('SELECT COUNT(*) FROM media_projects WHERE owner = ? AND created_at > ?');
    $rl->execute([$name, $now - 60]);
    if ((int)$rl->fetchColumn() >= 10) {
        json_out(['error' => 'Çox tez yaradırsan, bir dəqiqə gözlə'], 429);
    }
    $t = tl_empty($w, $h);
    if (isset($in['timeline'])) {
        try {
            $t = tl_normalize($in['timeline']);
        } catch (InvalidArgumentException $e) {
            json_out(['error' => $e->getMessage()], 400);
        }
    }
    $j = json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO media_projects (owner, title, timeline, version, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, $title, $j, 1, $name, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO media_project_versions (project_id, version, timeline, author, note, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$id, 1, $j, $name, 'yaradıldı', $now]);
    emit_event($pdo, 'media.project', '', '', $id, ['version' => 1, 'by' => $name, 'note' => 'yaradıldı', 'title' => $title]);
    json_out(['ok' => true, 'project' => tl_out(tl_project($pdo, $id))]);
}
if ($act === 'project_save') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    tl_base_ok($p, $in);
    try {
        $t = tl_normalize($in['timeline'] ?? null);
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
    $p = tl_commit($pdo, $p, $t, $name, tl_str($in['note'] ?? 'redaktə', 200), $now);
    json_out(['ok' => true, 'project' => tl_out($p)]);
}
if ($act === 'project_apply') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    tl_base_ok($p, $in);
    $ops = $in['ops'] ?? null;
    if (!is_array($ops) || !$ops || count($ops) > 300) {
        json_out(['error' => 'ops: 1–300 əməliyyatlıq siyahı lazımdır'], 400);
    }
    try {
        [$t, $made] = tl_apply(json_decode((string)$p['timeline'], true) ?: tl_empty(), $ops);
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
    $p = tl_commit($pdo, $p, $t, $name, tl_str($in['note'] ?? (count($ops) . ' əməliyyat'), 200), $now);
    json_out(['ok' => true, 'created' => $made, 'project' => tl_out($p)]);
}
if ($act === 'project_rename') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    $title = tl_str($in['title'] ?? '', 160);
    if ($title === '') {
        json_out(['error' => 'Ad boş ola bilməz'], 400);
    }
    $pdo->prepare('UPDATE media_projects SET title = ?, updated_at = ? WHERE id = ?')->execute([$title, $now, $p['id']]);
    emit_event($pdo, 'media.project', '', '', (int)$p['id'], ['version' => (int)$p['version'], 'by' => $name, 'note' => 'ad dəyişdi', 'title' => $title]);
    json_out(['ok' => true]);
}
if ($act === 'project_delete') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    if ($p['owner'] !== $name && !$isAdmin) {
        json_out(['error' => 'Yalnız layihənin sahibi silə bilər'], 403);
    }
    $pdo->prepare('DELETE FROM media_projects WHERE id = ?')->execute([$p['id']]);
    $pdo->prepare('DELETE FROM media_project_versions WHERE project_id = ?')->execute([$p['id']]);
    emit_event($pdo, 'media.project', '', '', (int)$p['id'], ['deleted' => true, 'by' => $name]);
    json_out(['ok' => true]);
}
if ($act === 'project_versions') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    $st = $pdo->prepare('SELECT version, author, note, created_at FROM media_project_versions WHERE project_id = ? ORDER BY version DESC LIMIT 50');
    $st->execute([$p['id']]);
    json_out(['versions' => $st->fetchAll()]);
}
if ($act === 'project_restore') {
    $p = tl_project($pdo, $in['id'] ?? 0);
    $st = $pdo->prepare('SELECT timeline FROM media_project_versions WHERE project_id = ? AND version = ?');
    $st->execute([$p['id'], (int)($in['version'] ?? 0)]);
    $j = $st->fetchColumn();
    if ($j === false) {
        json_out(['error' => 'Bu versiya tapılmadı'], 404);
    }
    $p = tl_commit($pdo, $p, tl_normalize(json_decode((string)$j, true)), $name, 'v' . (int)$in['version'] . ' geri qaytarıldı', $now);
    json_out(['ok' => true, 'project' => tl_out($p)]);
}

/* ---------- Aktivlər ---------- */
if ($act === 'asset_list') {
    $pid = (int)($in['project_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, user, url, type, name, size, project_id, kind, created_at FROM media_assets WHERE user = ? OR (project_id > 0 AND project_id = ?) ORDER BY id DESC LIMIT 200');
    $st->execute([$name, $pid]);
    $assets = $st->fetchAll();
    $g = $pdo->prepare("SELECT id, model, kind, prompt, result, created_at FROM media_gens WHERE user = ? AND status = 'done' ORDER BY id DESC LIMIT 60");
    $g->execute([$name]);
    foreach ($g->fetchAll() as $r) {
        foreach (json_decode((string)$r['result'], true) ?: [] as $m) {
            $assets[] = ['id' => 0, 'gen_id' => (int)$r['id'], 'user' => $name, 'url' => $m['url'], 'type' => $m['type'], 'name' => mb_substr($r['prompt'] ?: $r['model'], 0, 80),
                'size' => 0, 'project_id' => 0, 'kind' => 'generation', 'created_at' => (int)$r['created_at']];
        }
    }
    json_out(['assets' => $assets]);
}
if ($act === 'asset_delete') {
    $st = $pdo->prepare('SELECT * FROM media_assets WHERE id = ?');
    $st->execute([(int)($in['id'] ?? 0)]);
    $a = $st->fetch();
    if (!$a || ($a['user'] !== $name && !$isAdmin)) {
        json_out(['error' => 'Aktiv tapılmadı'], 404);
    }
    $pdo->prepare('DELETE FROM media_assets WHERE id = ?')->execute([$a['id']]);
    $fn = substr((string)strrchr((string)$a['url'], '='), 1);
    $dir = media_dir();
    if ($dir && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{3,4}$/', $fn)) {
        @unlink($dir . '/' . $fn);
    }
    json_out(['ok' => true]);
}
if ($act === 'upload_render') {
    $p = tl_project($pdo, $in['project_id'] ?? 0);
    $a = media_store_upload($pdo, $_FILES['file'] ?? null, $name, (int)$p['id'], 'export', $now);
    emit_event($pdo, 'media.project', '', '', (int)$p['id'], ['version' => (int)$p['version'], 'by' => $name, 'note' => '🎬 render: ' . $a['name'], 'export' => $a['url']]);
    json_out(['ok' => true] + $a);
}
