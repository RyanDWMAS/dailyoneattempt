-- Migration: Log-on exceptions (excused short days)
--
-- The short-log-on trigger (< 7h on a worked day) fires on any day an agent
-- was logged in but under the threshold — but it can't tell an approved short
-- day (sickness, appointment, half-day) from a genuine one. This table lets
-- Tina record those days so the weekly evaluation excludes them from the
-- short-day count. Each excused day applied to a week is also stamped into
-- weekly_triggers.excused_days for the audit trail.
--
-- Unlike the productivity tables, the entry form is frontend, so this table is
-- reachable by the anon role (with RLS). Full absences (no log-on at all) are
-- already ignored by the trigger and don't need an exception.

CREATE TABLE logon_exceptions (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    agent_name     text NOT NULL,
    exception_date date NOT NULL,
    reason         text NOT NULL
        CHECK (reason IN ('sickness', 'appointment', 'half_day', 'approved_early', 'other')),
    note           text,
    created_by     text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (agent_name, exception_date)
);

CREATE INDEX idx_logon_exceptions_date ON logon_exceptions (exception_date);

-- weekly_triggers gains the list of days that were excused when that week was
-- assessed, so the audit trail shows why a short day did not count.
ALTER TABLE weekly_triggers
    ADD COLUMN IF NOT EXISTS excused_days date[] NOT NULL DEFAULT ARRAY[]::date[];

-- A lightweight, anon-readable roster of agent names exactly as they appear in
-- the occupancy data, so the exceptions form can offer an accurate dropdown
-- (the name is the join key, so it must match exactly). Names only — no status
-- or disciplinary data is exposed to the frontend. The report keeps it fresh;
-- seed it now from the agents already tracked so the form works immediately.
CREATE TABLE productivity_agents (
    name       text PRIMARY KEY,
    updated_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO productivity_agents (name)
    SELECT agent_name FROM productivity_status
    ON CONFLICT (name) DO NOTHING;

GRANT ALL ON productivity_agents TO service_role;
GRANT SELECT ON productivity_agents TO anon;

ALTER TABLE productivity_agents ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anon select agents"
    ON productivity_agents FOR SELECT
    TO anon
    USING (true);

-- ── Grants & RLS ──
-- The report (service_role) reads/writes; the entry form (anon) reads the
-- agent list, inserts new exceptions, and deletes ones logged in error.
GRANT ALL ON logon_exceptions TO service_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON logon_exceptions TO anon;

ALTER TABLE logon_exceptions ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anon select exceptions"
    ON logon_exceptions FOR SELECT
    TO anon
    USING (true);

CREATE POLICY "Anon insert exceptions"
    ON logon_exceptions FOR INSERT
    TO anon
    WITH CHECK (true);

-- UPDATE lets the form upsert (re-logging a day updates its reason/note).
CREATE POLICY "Anon update exceptions"
    ON logon_exceptions FOR UPDATE
    TO anon
    USING (true)
    WITH CHECK (true);

CREATE POLICY "Anon delete exceptions"
    ON logon_exceptions FOR DELETE
    TO anon
    USING (true);
