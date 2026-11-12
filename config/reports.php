<?php

return [
    // Reports up to this many rows are emailed as an attached CSV; larger ones are built and sent as a private link.
    'attach_rows' => (int) env('REPORTS_ATTACH_ROWS', 5000),
];
