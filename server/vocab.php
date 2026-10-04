<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const KINDS = ['grievance', 'promise'];
const ORIGINS = ['event', 'lore'];
const DIRECTIONS = ['owed_by_me', 'owed_to_me'];
const STRENGTHS = ['will', 'will_try'];
const GRIEVANCE_STATUSES = ['open', 'resolved', 'let_go'];
const PROMISE_STATUSES = ['pending', 'fulfilled', 'disputed', 'broken', 'released'];
const OPEN_STATUSES = ['open', 'pending', 'disputed'];
const TRAJECTORIES = ['improving', 'worsening', 'unchanged'];
const WANTS = ['explanation', 'apology', 'restitution', 'keep_promise', 'thanks', 'unknown'];

const MAX_PROCESSED = 128;
const MAX_OPEN_ISSUES = 24;
const MAX_CLOSED_ISSUES = 16;
const MAX_EVIDENCE = 8;
const MAX_QUOTE_CHARS = 160;
const MAX_NOTE_CHARS = 240;
