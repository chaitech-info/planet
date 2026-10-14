<?php defined('JARVIS_MODULE') || exit;
// UI idarəsi və ekran konteksti: saytı səslə idarə et (pəncərələr, panellər, lövhə, kamera, göstərmə, tur). İcra: jarvis-ui.js
$s = fn(string $d) => ['type' => 'string', 'description' => $d];
return [
    'tools' => [
        ['name' => 'ui', 'description' => 'Drive the website UI (view changes only, never saves data): open/close windows and panels, switch views, board filters, open a task card, studio stations, camera zoom, sound, scroll. A planet or universe name also works as action.',
            'parameters' => ['type' => 'object', 'properties' => [
                'action' => $s('action id from the catalogue (e.g. board, new_task, messages, report, close, open_task, board_filter, zoom_in, universe, planet, back); Azerbaijani/English aliases accepted'),
                'target' => $s('optional: planet/universe/user/project/task/window name the action applies to'),
                'value' => $s('optional extra: on|off, today|yesterday, inbox, plan|scope, steps, up|down, title to prefill')],
                'required' => ['action']]],
        ['name' => 'list_ui_actions', 'description' => 'Full catalogue of UI actions and highlight targets with aliases and parameters.',
            'parameters' => ['type' => 'object', 'properties' => (object)[]]],
        ['name' => 'get_context', 'description' => 'What the user is looking at right now: route, view, universe, planet (open tasks, burning, running agents, active users), open windows, board filters, selected task, viewport.',
            'parameters' => ['type' => 'object', 'properties' => (object)[]]],
        ['name' => 'point_at', 'description' => 'Show the user something in the 3D space: fly the camera to a planet, universe, the Plan station or a user\'s spaceship and mark it with a holographic reticle.',
            'parameters' => ['type' => 'object', 'properties' => ['target' => $s('planet, universe, "plan", "map", or "<user> ship"'),
                'fly' => ['type' => 'boolean', 'description' => 'false = mark it from the current view without moving the camera (if visible)']], 'required' => ['target']]],
        ['name' => 'highlight', 'description' => 'Make a UI element glow so the user sees it: a header button, board column, task card, panel section, project card or planet. Use names, not CSS selectors.',
            'parameters' => ['type' => 'object', 'properties' => ['target' => $s('element by name, e.g. "messages button", "doing column", a task title, a project name, "zoom buttons"')], 'required' => ['target']]],
        ['name' => 'tour', 'description' => 'Cinematic flight over the planets, ~3.4 s per stop. Returns the ordered stops with key facts; narrate them briefly in order while it flies.',
            'parameters' => ['type' => 'object', 'properties' => ['scope' => $s('"all" (default) or a universe name')]]],
        ['name' => 'stop_tour', 'description' => 'Stop the running tour.', 'parameters' => ['type' => 'object', 'properties' => (object)[]]],
    ],
    'prompt' => <<<'TXT'
UI control: you can drive the whole website. ui {action, target?, value?} opens/closes windows and panels and changes views; it never saves data (create/change data only with the data tools and confirmation). Actions: panel, map, board, universe, planet, plan_station, back, next_planet, prev_planet, zoom_in, zoom_out, new_task, messages, report, account, my_ship, new_planet, mcp_satellites, new_meeting, new_plan, users, close, board_filter, board_status, open_task, panel_filter, send_tab, live_tab, test_planet, designer, prompt_generator, meeting_planet, agent, desktop, sound, quality, fullscreen, scroll, refresh (details: list_ui_actions). "Show me X" → point_at (3D) or highlight (buttons, columns, task cards). tour flies over planets: narrate the returned stops in order, one short sentence each; the user speaking stops it (stop_tour to stop on request).
Lines starting with [Screen] are silent updates of what the user sees; never answer them. "this / bu / burada / bura / bunu / buradakı" refers to the latest [Screen]; call get_context if unsure. Tool results with ok:false: tell the user briefly and offer the closest option.
TXT,
];
