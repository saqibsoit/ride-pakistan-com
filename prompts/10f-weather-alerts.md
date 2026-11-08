# Prompt 10f: Weather alerts by email

Read `CLAUDE.md` and `docs/PLAN.md`. Start in plan mode.

1. Settings: venue latitude and longitude (admin only, never shown publicly), alert thresholds (rain probability %, precipitation mm, wind km/h, heat °C, cold °C, thunderstorm codes), who receives alerts (admins, staff, trainers).
2. Use the Open-Meteo forecast API (free, no key) for the venue coordinates. Fetch hourly forecasts twice a day (e.g. 18:00 and 06:00 Asia/Karachi) for slots in the next 48 hours. Cache responses; handle API failure gracefully (log + one admin email if forecasts fail for 12 hours).
3. If any threshold is crossed during a slot’s time, email the selected team members once per slot per severity change: slot, riders booked, forecast details, and buttons to open the run sheet or cancel/move the slot in admin.
4. Customer weather notice: from the slot page in admin, an admin can send one email to all riders booked in that slot using a template ("weather update", "session moved", "session cancelled"). Cancelling still goes through the existing slot cancellation flow so credits and refunds are handled.
5. Show a small weather badge on the run sheet and the admin slot list for the next 48 hours.

Acceptance: tests with mocked API responses (thresholds, deduplication, failure handling), email content tests, permission tests. Work on a feature branch.
