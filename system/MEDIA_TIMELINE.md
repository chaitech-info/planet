# MEDIA Timeline: komandanın video redaktoru (insan + Claude)

Edit Media → **🎞 Timeline**: OpenCut tipli çox trekli video redaktoru. Eyni layihəni brauzerdə insan, Claude Code isə
**planet-media MCP** ilə redaktə edir. Hər dəyişiklik versiyadır (son 50 saxlanır). Başqası dəyişəndə açıq redaktor
`media.project` realtime hadisəsi ilə son versiyanı dərhal yükləyir.

## Hissələr
| Hissə | Fayl |
|---|---|
| Layihə API (sessiya və ya `X-Team-Token`) | `public_html/media.php` → `media_timeline.php` |
| Cədvəllər | `media_projects`, `media_project_versions`, `media_assets` (`db.php`, sxem 9) |
| Brauzer redaktoru | `public_html/media-timeline.js` (+ `media.js`-dəki Edit Media) |
| Fayllar (Range dəstəyi ilə) | `public_html/mediafile.php` → `data/media/` |
| Claude alətləri | `agent/planet_media_mcp.py` (kit-də `~/claude-team-kit/agent/`) |

## Timeline JSON (v1)
Bütün vaxtlar saniyədir. Siyahıda **sonrakı trek üstdə** çəkilir (redaktorda yuxarıda görünür).
```json
{ "v": 1, "width": 1080, "height": 1920, "fps": 30, "background": "#000000", "duration": 9,
  "tracks": [
    { "id": "t_video1", "kind": "video", "name": "Video 1", "muted": false, "hidden": false, "clips": [
      { "id": "c_a1", "type": "video", "src": "https://…/mediafile.php?f=….mp4", "start": 0, "dur": 3, "in": 0,
        "speed": 1, "volume": 1, "x": 0.5, "y": 0.5, "scale": 1, "rotation": 0, "opacity": 1, "fit": "cover", "fadeIn": 0, "fadeOut": 0.3 } ] },
    { "id": "t_text1", "kind": "text", "name": "Mətn", "clips": [
      { "id": "c_t1", "type": "text", "text": "Salam!", "start": 0.5, "dur": 2, "x": 0.5, "y": 0.3,
        "style": { "font": "Inter", "size": 96, "weight": 700, "color": "#ffffff", "bg": "transparent", "stroke": "transparent", "align": "center", "anim": "pop" } } ] } ] }
```
- Trek növləri: `video` (video + image kliplər), `audio`, `text`, `caption`.
- `in` mənbədən başlanğıc (video/səs); `speed` 0.25–4; `volume` 0–2; `x`/`y` kadra nisbətən mərkəz (0..1); `fit` cover|contain|fill.
- Animasiya (`style.anim`): `none`, `fade`, `pop`, `slide-up`, `typewriter`.
- Limitlər: 16 trek, 600 klip, 1 saat; `src` yalnız http(s) URL. Server hər saxlamada formatı yoxlayıb təmizləyir.

## Redaktə əməliyyatları (`project_apply`, MCP `media_apply_edits`)
Ardıcıl tətbiq olunur, hər biri yoxlanır, hamısı **bir versiya** kimi saxlanır. `base_version` köhnədirsə 409.
| op | sahələr |
|---|---|
| `add_track` | `kind`, `name?`, `below?` |
| `remove_track` | `track` |
| `add_clip` | `src`, `type?` (URL-dən təxmin), `track?`, `start?` (yoxdursa trekin sonu), `dur`, `in?`, digər xüsusiyyətlər |
| `add_text` | `text`, `start`, `dur`, `track?`, `x?`, `y?`, `style?` |
| `add_captions` | `cues: [{start, end, text}]`, `style?`, `track?`, `replace?` |
| `move` | `clip`, `start?`, `track?` |
| `trim` | `clip`, `start?`, `dur?` və ya `end?`, `in?` |
| `split` | `clip`, `at` (timeline saniyəsi, klipin içində) |
| `delete` | `clip`, `ripple?` |
| `set_props` | `clip`, `props: {…}` (stil birləşdirilir) |
| `set_track` | `track`, `name?`, `muted?`, `hidden?` |
| `set_canvas` | `preset?` (landscape, reels, square, portrait), `width?`, `height?`, `fps?`, `background?` |

## API (`media.php`, POST)
`project_list`, `project_get {id}`, `project_create {title, preset?}`, `project_save {id, timeline, base_version}`,
`project_apply {id, ops, base_version?, note?}`, `project_rename`, `project_delete` (sahib/admin), `project_versions`,
`project_restore {id, version}`, `asset_list {project_id?}`, `asset_delete` (yalnız sessiya), `upload` (multipart `file`),
`upload_render {project_id}` (multipart MP4 → layihənin renderləri).
Token ilə (`X-Team-Token`, hook/agent tokeni) yalnız layihə/aktiv/render/generasiya əməliyyatları icazəlidir; fal açarı yalnız brauzerdən.

## Claude: planet-media MCP
- Quraşdırma: `hook/install.sh` avtomatik `claude mcp add planet-media -s user -- python3 …/agent/planet_media_mcp.py` edir.
  Yoxlama: `python3 ~/claude-team-kit/agent/planet_media_mcp.py --check`.
- Alətlər: `media_list_projects`, `media_get_project`, `media_create_project`, `media_apply_edits`, `media_list_versions`,
  `media_restore_version`, `media_list_assets`, `media_upload_file` (ev qovluğundakı media faylı, ≤ 50 MB; `as_render` → render),
  `media_timeline_schema`.
- Token `~/.claude-team-dash.conf`-dan yalnız MCP prosesi tərəfindən oxunur; Claude onu görmür.
- Planet agenti: `dev` profilində `mcp__planet-media` alətləri icazəlidir (`ops`/`full`-da bütün MCP-lər). Redaktordakı
  "🤖 Claude ilə redaktə" `media-studio` proyektinə komanda göndərir.

## Brauzerdə
- **Önizləmə və eksport eyni funksiyadan** (`tlDraw`): eksport `mediabunny` (WebCodecs, MPL-2.0) ilə dəqiq kadr oxuyur, səsi
  `OfflineAudioContext` ilə qarışdırır (sürət, səs səviyyəsi, fade), H.264 + AAC MP4 yazır və istəsən `upload_render` ilə yükləyir.
- **Avtomatik altyazı**: `transformers.js` Whisper (Apache-2.0) Web Worker-də, WebGPU varsa onunla; səs serverə getmir.
- Klaviatura: boşluq oynat, S kəs, Del sil, ⌘Z / ⇧⌘Z, ⌘D kopya, ←/→ kadr (Shift: 1 san), Ctrl/⌘ + təkər miqyas.
- Yükləmə limiti serverin PHP ayarından asılıdır (`upload_max_filesize`, `post_max_size`): render üçün ən azı 64M tövsiyə olunur.
