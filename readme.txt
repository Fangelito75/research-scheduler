=== Research Scheduler (Doodle-like) ===
Contributors: (internal)
Tags: scheduling, poll, meeting, doodle
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 0.1.10
License: GPLv2 or later

== Description ==
Plugin tipo Doodle para crear encuestas de disponibilidad de reuniones y recoger votos con un enlace compartible.

== Instalación ==
1) Sube el ZIP como plugin en WordPress y actívalo.
2) Crea una página "Crear encuesta" con el shortcode: [rs_create_meeting]
3) Crea una página "Votación" con el shortcode: [rs_meeting_poll]
4) Ve a Admin → Research Scheduler → Ajustes y selecciona la página "Votación".

== Uso ==
- Los usuarios logueados pueden crear una encuesta y obtendrán un enlace para compartir.
- Cualquiera con el enlace puede votar introduciendo su email y marcando franjas horarias.
- En Admin → Research Scheduler puedes cerrar/reabrir/eliminar encuestas.

== Notas ==
- MVP: votos tipo "sí" (checkboxes). Se puede ampliar a Sí/Quizá/No, lista de invitados, verificación por email, recordatorios, ICS, etc.
