<?php
if (!defined('ABSPATH')) { exit; }

add_shortcode('rs_create_meeting', 'rsdl_sc_create_meeting');
add_shortcode('rs_meeting_poll', 'rsdl_sc_meeting_poll');

/**
 * Creator shortcode: [rs_create_meeting]
 * Requires logged-in user (recommended).
 */
function rsdl_sc_create_meeting($atts) {
    if (!is_user_logged_in()) {
        return '<div class="rsdl-box rsdl-warning">You must be logged in to create a poll.</div>';
    }

    $current_user = wp_get_current_user();
    $prefill_email = $current_user->user_email;

    $out = '';

    if (!empty($_POST['rsdl_action']) && $_POST['rsdl_action'] === 'create_meeting') {
        if (!isset($_POST['rsdl_nonce']) || !wp_verify_nonce($_POST['rsdl_nonce'], 'rsdl_create_meeting')) {
            return '<div class="rsdl-box rsdl-error">Error de seguridad (nonce).</div>';
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $creator_email = sanitize_email(wp_unslash($_POST['creator_email'] ?? $prefill_email));
        $timezone = sanitize_text_field(wp_unslash($_POST['timezone'] ?? 'Europe/Madrid'));

        $start_date = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        $day_start = sanitize_text_field(wp_unslash($_POST['day_start'] ?? '10:00'));
        $day_end = sanitize_text_field(wp_unslash($_POST['day_end'] ?? '18:00'));
        $slot_duration = intval($_POST['slot_duration'] ?? 60);
        $slot_step = intval($_POST['slot_step'] ?? $slot_duration);

        $deadline = sanitize_text_field(wp_unslash($_POST['deadline'] ?? ''));
        $manual_slots_json = wp_unslash($_POST['manual_slots_json'] ?? '');
        $deadline_dt = null;
        if (!empty($deadline)) {
            // store as mysql datetime (site timezone)
            $deadline_dt = date('Y-m-d 23:59:59', strtotime($deadline));
        }

        if (empty($title) || empty($creator_email) || empty($start_date) || empty($end_date)) {
            $out .= '<div class="rsdl-box rsdl-error">Missing required fields.</div>';
        } else {
            global $wpdb;
            $meetings = rsdl_table('rsdl_meetings');
            $slots = rsdl_table('rsdl_slots');

            $token = rsdl_generate_token(32);
            $now = rsdl_now_mysql();

            $wpdb->insert($meetings, array(
                'token' => $token,
                'title' => $title,
                'description' => $description,
                'creator_user_id' => get_current_user_id(),
                'creator_email' => $creator_email,
                'timezone' => $timezone,
                'status' => 'open',
                'deadline' => $deadline_dt,
                'winning_slot_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ), array('%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s'));

            $meeting_id = $wpdb->insert_id;

            // Generate slots in UTC stored as MySQL datetime (UTC)
            $tz = new DateTimeZone($timezone);
            $utc = new DateTimeZone('UTC');

            $now2 = $now;

            $manual_slots = array();
            if (!empty($manual_slots_json)) {
                $decoded = json_decode($manual_slots_json, true);
                if (is_array($decoded)) {
                    $manual_slots = $decoded;
                }
            }

            if (!empty($manual_slots)) {
                // Manual selection from visual calendar picker. Each item: "YYYY-MM-DD HH:MM"
                foreach ($manual_slots as $local_start) {
                    $local_start = sanitize_text_field($local_start);
                    if (empty($local_start)) continue;

                    $dt_local = DateTime::createFromFormat('Y-m-d H:i', $local_start, $tz);
                    if (!$dt_local) continue;

                    $dt_end_local = (clone $dt_local)->add(new DateInterval('PT' . max(1, $slot_duration) . 'M'));

                    $dt_utc = (clone $dt_local); $dt_utc->setTimezone($utc);
                    $dt_end_utc = (clone $dt_end_local); $dt_end_utc->setTimezone($utc);

                    $wpdb->insert($slots, array(
                        'meeting_id' => $meeting_id,
                        'start_dt' => $dt_utc->format('Y-m-d H:i:s'),
                        'end_dt' => $dt_end_utc->format('Y-m-d H:i:s'),
                        'created_at' => $now2,
                    ), array('%d','%s','%s','%s'));
                }
            } else {
                // Automatic generation from date range + day window
                $start = DateTime::createFromFormat('Y-m-d', $start_date, $tz);
                $end = DateTime::createFromFormat('Y-m-d', $end_date, $tz);
                if (!$start || !$end) {
                    $out .= '<div class="rsdl-box rsdl-error">Invalid dates.</div>';
                } else {
                    $day_start_parts = explode(':', $day_start);
                    $day_end_parts = explode(':', $day_end);

                    $interval_day = new DateInterval('P1D');
                    $period = new DatePeriod($start, $interval_day, (clone $end)->add($interval_day));

                    foreach ($period as $day) {
                        $d = clone $day;
                        $d->setTime(intval($day_start_parts[0] ?? 10), intval($day_start_parts[1] ?? 0), 0);
                        $d_end = clone $day;
                        $d_end->setTime(intval($day_end_parts[0] ?? 18), intval($day_end_parts[1] ?? 0), 0);

                        while ($d < $d_end) {
                            $slot_end = (clone $d)->add(new DateInterval('PT' . $slot_duration . 'M'));
                            if ($slot_end > $d_end) break;

                            $d_utc = (clone $d); $d_utc->setTimezone($utc);
                            $slot_end_utc = (clone $slot_end); $slot_end_utc->setTimezone($utc);

                            $wpdb->insert($slots, array(
                                'meeting_id' => $meeting_id,
                                'start_dt' => $d_utc->format('Y-m-d H:i:s'),
                                'end_dt' => $slot_end_utc->format('Y-m-d H:i:s'),
                                'created_at' => $now2,
                            ), array('%d','%s','%s','%s'));

                            $d->add(new DateInterval('PT' . max(1, $slot_step) . 'M'));
                        }
                    }
                }
            }
                $link = esc_url(rsdl_meeting_link($token));
                $out .= '<div class="rsdl-box rsdl-success"><strong>Poll created.</strong><br>Share link: <a href="' . $link . '">' . $link . '</a></div>';
         }
    }

    $out .= '<div class="rsdl-box">
        <h3>Create meeting poll</h3>
        <form method="post" class="rsdl-form">
            <input type="hidden" name="rsdl_action" value="create_meeting" />
            ' . wp_nonce_field('rsdl_create_meeting', 'rsdl_nonce', true, false) . '

            <label>Title *</label>
            <input type="text" name="title" required />

            <label>Description</label>
            <textarea name="description" rows="4"></textarea>

            <label>Creator email *</label>
            <input type="email" name="creator_email" value="' . esc_attr($prefill_email) . '" required />

            <div class="rsdl-grid">
                <div>
                    <label>Start date *</label>
                    <input type="date" name="start_date" required />
                </div>
                <div>
                    <label>End date *</label>
                    <input type="date" name="end_date" required />
                </div>
            </div>

            <div class="rsdl-grid">
                <div>
                    <label>Day start time</label>
                    <input type="time" name="day_start" value="10:00" />
                </div>
                <div>
                    <label>Day end time</label>
                    <input type="time" name="day_end" value="18:00" />
                </div>
            </div>

            <div class="rsdl-grid">
                <div>
                    <label>Slot duration (min)</label>
                    <select name="slot_duration">
                        <option value="30">30</option>
                        <option value="60" selected>60</option>
                    </select>
                </div>
                <div>
                    <label>Step (min)</label>
                    <select name="slot_step">
                        <option value="30">30</option>
                        <option value="60" selected>60</option>
                    </select>
                </div>
            </div>

            <div class="rsdl-grid">
                <div>
                    <label>Time zone</label>
                    <input type="text" name="timezone" value="Europe/Madrid" />
                    <small>Ej: Europe/Madrid</small>
                </div>
                <div>
                    <label>Deadline (opcional)</label>
                    <input type="date" name="deadline" />
                </div>
            </div>

            
            <hr class="rsdl-hr"/>
            <h4>Select slots visually (opcional)</h4>
            <p class="rsdl-small">Puedes seleccionar las franjas directamente en el calendario (clic o arrastrar). Si seleccionas aquí, se ignorará la generación automática.</p>

            <input type="hidden" name="manual_slots_json" id="rsdl_manual_slots_json" value="" />

            <div id="rsdl-slot-picker" class="rsdl-picker" data-rsdl-slot-picker></div>

            <button type="submit" class="rsdl-btn">Create and generate link</button>
        </form>

        
    </div>';

    return $out;
}

/**
 * Poll shortcode: [rs_meeting_poll]
 * Reads token from ?rs_token=...
 */
function rsdl_sc_meeting_poll($atts) {
    $token_raw = get_query_var('rs_token');
    if (empty($token_raw)) { $token_raw = $_GET['rs_token'] ?? ''; }
    $token = sanitize_text_field(wp_unslash($token_raw));
    if (empty($token)) {
        return '<div class="rsdl-box rsdl-warning">Falta el parámetro <code>rs_token</code> en la URL.</div>';
    }

    global $wpdb;
    $meetings = rsdl_table('rsdl_meetings');
    $slots    = rsdl_table('rsdl_slots');
    $votes    = rsdl_table('rsdl_votes');

    $meeting = $wpdb->get_row($wpdb->prepare("SELECT * FROM $meetings WHERE token = %s", $token));
    if (!$meeting) {
        return '<div class="rsdl-box rsdl-error">Poll not found.</div>';
    }

    // Auto close if deadline passed
    if ($meeting->status === 'open' && rsdl_is_deadline_passed($meeting)) {
        rsdl_close_meeting(intval($meeting->id));
        $meeting = $wpdb->get_row($wpdb->prepare("SELECT * FROM $meetings WHERE id = %d", intval($meeting->id)));
    }

    $timezone = $meeting->timezone ?: 'Europe/Madrid';

    $out = '<div class="rsdl-box">
        <h2 class="rsdl-title">' . esc_html($meeting->title) . '</h2>
        ' . (!empty($meeting->description) ? '<div class="rsdl-desc">' . nl2br(esc_html($meeting->description)) . '</div>' : '') . '
        <div class="rsdl-meta">
            <span><strong>Status:</strong> ' . esc_html($meeting->status) . '</span>
            ' . (!empty($meeting->deadline) ? '<span><strong>Vote until:</strong> ' . esc_html(date_i18n('d/m/Y', strtotime($meeting->deadline))) . '</span>' : '') . '
            <span><strong>Time zone:</strong> ' . esc_html($timezone) . '</span>
        </div>
    ';

    // Fetch slots
    $slot_rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $slots WHERE meeting_id = %d ORDER BY start_dt ASC", intval($meeting->id)));
    if (empty($slot_rows)) {
        $out .= '<div class="rsdl-warning">No hay franjas configuradas.</div></div>';
        return $out;
    }

    // Vote submission (only if open)
    if ($meeting->status === 'open' && !empty($_POST['rsdl_action']) && $_POST['rsdl_action'] === 'submit_vote') {
        if (!isset($_POST['rsdl_nonce']) || !wp_verify_nonce($_POST['rsdl_nonce'], 'rsdl_submit_vote_' . $token)) {
            $out .= '<div class="rsdl-box rsdl-error">Error de seguridad (nonce).</div>';
        } else {
            $voter_email = sanitize_email(wp_unslash($_POST['voter_email'] ?? ''));
            $slot_states = $_POST['slot_states'] ?? array();
            if (empty($voter_email)) {
                $out .= '<div class="rsdl-box rsdl-error">Introduce un email válido.</div>';
            } else {
                // Clear previous votes for this voter in this meeting, then insert new
                $wpdb->query($wpdb->prepare("DELETE FROM $votes WHERE meeting_id = %d AND voter_email = %s", intval($meeting->id), $voter_email));

                $now = rsdl_now_mysql();
                if (is_array($slot_states)) {
                    foreach ($slot_states as $slot_id => $vote_value) {
                        $slot_id = intval($slot_id);
                        $vote_value = sanitize_text_field($vote_value);
                        if (!in_array($vote_value, array('yes','maybe'), true)) { continue; }
                        if ($slot_id > 0) {
                            $wpdb->insert($votes, array(
                                'meeting_id' => intval($meeting->id),
                                'slot_id' => $slot_id,
                                'voter_email' => $voter_email,
                                'vote_value' => $vote_value,
                                'created_at' => $now,
                            ), array('%d','%d','%s','%s','%s'));
                        }
                    }
                }
                $out .= '<div class="rsdl-box rsdl-success">Vote saved. Thank you!</div>';
                // Notify creator (safe)
                if (function_exists('rsdl_notify_creator_vote')) { rsdl_notify_creator_vote($meeting, $voter_email, $slot_states ?? array()); }

            }
        }
    }

    // Counts
    $counts = $wpdb->get_results($wpdb->prepare("
        SELECT s.id as slot_id, s.start_dt, s.end_dt,
        SUM(CASE WHEN v.vote_value='yes' THEN 1 ELSE 0 END) as yes_votes,
        SUM(CASE WHEN v.vote_value='maybe' THEN 1 ELSE 0 END) as maybe_votes
        FROM $slots s
        LEFT JOIN $votes v ON v.slot_id = s.id
        WHERE s.meeting_id = %d
        GROUP BY s.id
        ORDER BY s.start_dt ASC
    ", intval($meeting->id)));

    $maxVotes = 0;
    foreach ($counts as $c) {
        $score = (intval($c->yes_votes) * 2) + intval($c->maybe_votes);
        $maxVotes = max($maxVotes, $score);
    }

    if ($meeting->status === 'closed') {
        $out .= '<h3>Result</h3>';
        if ($maxVotes === 0) {
            $out .= '<div class="rsdl-warning">No votes yet.</div>';
        } else {
			$winners = array_filter($counts, function($c) use ($maxVotes) {
				$score = (intval($c->yes_votes) * 2) + intval($c->maybe_votes);
				return $score === $maxVotes;
			});
            $out .= '<div class="rsdl-box rsdl-success"><strong>Opción(es) más votada(s):</strong><ul>';
            foreach ($winners as $w) {
				$out .= '<li>' . esc_html(rsdl_format_dt_local($w->start_dt, $timezone)) . ' – ' . esc_html(rsdl_format_dt_local($w->end_dt, $timezone, 'H:i')) . ' (' . intval($w->yes_votes) . ' sí, ' . intval($w->maybe_votes) . ' quizá)</li>';
            }
            $out .= '</ul></div>';
        }

        $out .= '<h4>Counts</h4>';
		$out .= '<table class="rsdl-table"><thead><tr><th>Franja</th><th>Sí</th><th>Quizá</th><th>Puntuación</th></tr></thead><tbody>';
        foreach ($counts as $c) {
			$out .= '<tr><td>' . esc_html(rsdl_format_dt_local($c->start_dt, $timezone)) . ' – ' . esc_html(rsdl_format_dt_local($c->end_dt, $timezone, 'H:i')) . '</td><td>' . intval($c->yes_votes) . '</td><td>' . intval($c->maybe_votes) . '</td><td>' . ((intval($c->yes_votes) * 2) + intval($c->maybe_votes)) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        return $out;
    }

    // Open poll: show "Google Calendar-like" grid (day columns, time rows) with Yes/Maybe states
    $tz = new DateTimeZone($timezone);
    $dates = array();
    $slot_map = array(); // [date][time] => info
    $time_minutes = array();

    foreach ($counts as $c) {
        try {
            $s = new DateTime($c->start_dt, new DateTimeZone('UTC'));
            $e = new DateTime($c->end_dt, new DateTimeZone('UTC'));
            $s->setTimezone($tz);
            $e->setTimezone($tz);

            $date_key = $s->format('Y-m-d');
            $time_key = $s->format('H:i');

            if (!in_array($date_key, $dates, true)) { $dates[] = $date_key; }
            if (!isset($slot_map[$date_key])) { $slot_map[$date_key] = array(); }

            $slot_map[$date_key][$time_key] = array(
                'slot_id' => intval($c->slot_id),
                'start'   => $s,
                'end'     => $e,
                'yes'     => intval($c->yes_votes),
                'maybe'   => intval($c->maybe_votes),
            );

            $time_minutes[] = intval($s->format('H'))*60 + intval($s->format('i'));
        } catch (Exception $ex) { /* ignore */ }
    }
    sort($dates);

    // infer step (min 30, max 60)
    $step = 60;
    sort($time_minutes);
    $diffs = array();
    for ($i=1; $i<count($time_minutes); $i++) {
        $d = $time_minutes[$i]-$time_minutes[$i-1];
        if ($d>0) { $diffs[] = $d; }
    }
    if (!empty($diffs)) {
        $g = $diffs[0];
        foreach ($diffs as $d) { $g = rsdl_int_gcd($g, $d); }
        $step = max(30, min(60, $g));
    }

    $minT = !empty($time_minutes) ? min($time_minutes) : 10*60;
    $maxT = !empty($time_minutes) ? max($time_minutes) : 18*60;
    foreach ($counts as $c) {
        try {
            $e = new DateTime($c->end_dt, new DateTimeZone('UTC'));
            $e->setTimezone($tz);
            $m = intval($e->format('H'))*60 + intval($e->format('i'));
            $maxT = max($maxT, $m);
        } catch (Exception $ex) {}
    }

    $times = array();
    for ($t=$minT; $t<$maxT; $t+=$step) {
        $h = floor($t/60); $mi = $t%60;
        $times[] = sprintf('%02d:%02d', $h, $mi);
    }

    // best score highlight
    $bestScore = 0;
    foreach ($counts as $c) {
        $score = (intval($c->yes_votes) * 2) + intval($c->maybe_votes);
        $bestScore = max($bestScore, $score);
    }

    $out .= '<h3>Vote availability</h3>
        <form method="post" class="rsdl-form">
            <input type="hidden" name="rsdl_action" value="submit_vote" />
            ' . wp_nonce_field('rsdl_submit_vote_' . $token, 'rsdl_nonce', true, false) . '
            <label>Your email *</label>
            <input type="email" name="voter_email" required placeholder="nombre@dominio.com" />

            <div class="rsdl-legend">
              <span class="rsdl-pill rsdl-pill-yes">Sí</span>
              <span class="rsdl-pill rsdl-pill-maybe">Quizá</span>
              <span class="rsdl-pill rsdl-pill-none">—</span>
              <span class="rsdl-small">Clic: alterna Sí → Quizá → vacío. Arrastrar: marca Sí.</span>
            </div>

            <div class="rsdl-gcal-wrap" data-rsdl-calendar data-rsdl-vote-grid>
              <div class="rsdl-gcal-head">
                <div class="rsdl-gcal-timehead"></div>';

    foreach ($dates as $d) {
        $out .= '<div class="rsdl-gcal-dayhead">' . esc_html(date_i18n('D d/m', strtotime($d))) . '</div>';
    }

    $out .= '</div><div class="rsdl-gcal-body">';

    foreach ($times as $timeLabel) {
        $out .= '<div class="rsdl-gcal-row">
            <div class="rsdl-gcal-time">' . esc_html($timeLabel) . '</div>';
        foreach ($dates as $d) {
            if (isset($slot_map[$d][$timeLabel])) {
                $info = $slot_map[$d][$timeLabel];
                $score = ($info['yes'] * 2) + $info['maybe'];
                $isTop = ($bestScore > 0 && $score === $bestScore);
                $title = esc_attr($info['start']->format('d/m/Y H:i') . ' – ' . $info['end']->format('H:i') . ' (sí: ' . $info['yes'] . ', quizá: ' . $info['maybe'] . ')');
                $out .= '<div class="rsdl-gcal-cell rsdl-available ' . ($isTop ? 'rsdl-top' : '') . '" data-slot-id="' . intval($info['slot_id']) . '" data-state="" title="' . $title . '">
                    <span class="rsdl-gcal-v">' . intval($info['yes']) . ' / ' . intval($info['maybe']) . '</span>
                </div>';
            } else {
                $out .= '<div class="rsdl-gcal-cell rsdl-unavailable"></div>';
            }
        }
        $out .= '</div>';
    }

    $out .= '</div></div>';

    // Hidden inputs to submit slot states
    $out .= '<div class="rsdl-hidden">';
    foreach ($counts as $c) {
        $out .= '<input class="rsdl-slot-state" type="hidden" name="slot_states[' . intval($c->slot_id) . ']" value="" data-slot-id="' . intval($c->slot_id) . '" />';
    }
    $out .= '</div>';

    $out .= '<button type="submit" class="rsdl-btn">Submit</button>
        </form>
    </div>';

    return $out;
}

