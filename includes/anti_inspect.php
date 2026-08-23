<?php

declare(strict_types=1);

if (!function_exists('renderAntiInspectScript')) {
    function renderAntiInspectScript(): void
    {
        echo <<<'HTML'
<script>
(function () {
    function blockEvent(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    }

    document.addEventListener('contextmenu', blockEvent);
    document.addEventListener('keydown', function (event) {
        const key = (event.key || '').toLowerCase();
        const blockedCtrlShift = event.ctrlKey && event.shiftKey && (key === 'i' || key === 'j' || key === 'c');
        const blockedCtrlOnly = event.ctrlKey && (key === 'u' || key === 's');
        const blockedKeyOnly = key === 'f12';

        if (blockedCtrlShift || blockedCtrlOnly || blockedKeyOnly) {
            blockEvent(event);
        }
    });
})();
</script>
HTML;
    }
}
