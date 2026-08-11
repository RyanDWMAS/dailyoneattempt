-- Migration: Productivity trigger tracking
--
-- Tracks per-agent productivity status across the four-stage process
-- (Informal -> First -> Second -> Final), and logs each week's trigger
-- evaluation so the system has an audit trail.
--
-- Triggers (any of these in a week breaches productivity):
--   1. Not Ready > 3% of log-on time
--   2. Break > 9.5% of log-on time (excluding Meeting break time)
--   3. Wrap > 2% of log-on time
--   4. Log-on < 7h on any worked day
--
-- Note: Supabase's April 2026 visibility change means new tables in
-- the public schema are no longer auto-exposed to the Data API. We
-- explicitly grant service_role access below. Nothing on the frontend
-- touches these tables.

CREATE TABLE productivity_status (
    agent_name                text PRIMARY KEY,
    current_stage             text NOT NULL DEFAULT 'none'
        CHECK (current_stage IN ('none', 'informal', 'first', 'second', 'final')),
    stage_entered_at          date,
    consecutive_trigger_weeks integer NOT NULL DEFAULT 0,
    awaiting_hr               boolean NOT NULL DEFAULT false,
    last_assessed_week        date,
    updated_at                timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE weekly_triggers (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    agent_name       text NOT NULL,
    week_start       date NOT NULL,
    week_end         date NOT NULL,
    triggered        boolean NOT NULL,
    triggers_fired   text[] NOT NULL DEFAULT ARRAY[]::text[],
    not_ready_pct    numeric,
    break_pct        numeric,
    wrap_pct         numeric,
    short_login_days integer NOT NULL DEFAULT 0,
    log_on_seconds   integer,
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (agent_name, week_start)
);

CREATE INDEX idx_weekly_triggers_agent ON weekly_triggers (agent_name, week_start DESC);

GRANT ALL ON productivity_status TO service_role;
GRANT ALL ON weekly_triggers     TO service_role;

ALTER TABLE productivity_status ENABLE ROW LEVEL SECURITY;
ALTER TABLE weekly_triggers     ENABLE ROW LEVEL SECURITY;
