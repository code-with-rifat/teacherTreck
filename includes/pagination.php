<?php
/**
 * Shared pagination helpers
 */

declare(strict_types=1);

/** @return array{page:int,per:int,offset:int,param:string} */
function paginate_request(int $perPage = 20, string $param = 'page'): array
{
    $page = max(1, (int) ($_GET[$param] ?? 1));
    $perPage = max(5, min(100, $perPage));
    return [
        'page' => $page,
        'per' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'param' => $param,
    ];
}

/**
 * @param array{page:int,per:int,offset:int,param:string} $p
 * @return array{total:int,page:int,per:int,pages:int,offset:int,from:int,to:int,has_prev:bool,has_next:bool,param:string}
 */
function paginate_meta(int $total, array $p): array
{
    $total = max(0, $total);
    $pages = max(1, (int) ceil($total / max(1, $p['per'])));
    $page = min(max(1, $p['page']), $pages);
    $offset = ($page - 1) * $p['per'];
    return [
        'total' => $total,
        'page' => $page,
        'per' => $p['per'],
        'pages' => $pages,
        'offset' => $offset,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => min($total, $offset + $p['per']),
        'has_prev' => $page > 1,
        'has_next' => $page < $pages,
        'param' => $p['param'] ?? 'page',
    ];
}

/** Build query string keeping filters, swapping page. */
function paginate_href(int $page, array $extra = [], string $param = 'page'): string
{
    $q = array_merge($_GET, $extra, [$param => $page]);
    return '?' . http_build_query($q);
}

function render_pager(array $meta): string
{
    if (($meta['pages'] ?? 1) <= 1) {
        return '';
    }
    $page = (int) $meta['page'];
    $pages = (int) $meta['pages'];
    $from = (int) $meta['from'];
    $to = (int) $meta['to'];
    $total = (int) $meta['total'];
    $param = (string) ($meta['param'] ?? 'page');

    $html = '<nav class="app-pager" aria-label="Pagination">';
    $html .= '<span class="app-pager-meta">' . h((string) $from) . '–' . h((string) $to) . ' of ' . h((string) $total) . '</span>';
    $html .= '<div class="app-pager-links">';
    if (!empty($meta['has_prev'])) {
        $html .= '<a class="btn btn-secondary btn-sm" href="' . h(paginate_href($page - 1, [], $param)) . '">← Prev</a>';
    } else {
        $html .= '<span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">← Prev</span>';
    }

    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $html .= '<a class="app-pager-num" href="' . h(paginate_href(1, [], $param)) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="app-pager-ellipsis">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<span class="app-pager-num is-current">' . $i . '</span>';
        } else {
            $html .= '<a class="app-pager-num" href="' . h(paginate_href($i, [], $param)) . '">' . $i . '</a>';
        }
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<span class="app-pager-ellipsis">…</span>';
        }
        $html .= '<a class="app-pager-num" href="' . h(paginate_href($pages, [], $param)) . '">' . $pages . '</a>';
    }

    if (!empty($meta['has_next'])) {
        $html .= '<a class="btn btn-secondary btn-sm" href="' . h(paginate_href($page + 1, [], $param)) . '">Next →</a>';
    } else {
        $html .= '<span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Next →</span>';
    }
    $html .= '</div></nav>';
    return $html;
}
