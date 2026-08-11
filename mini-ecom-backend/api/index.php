<?php

// Vercel's PHP runtime treats every file under /api as its own serverless function entry
// point; this just hands the request straight to Laravel's real front controller.
require __DIR__.'/../public/index.php';
