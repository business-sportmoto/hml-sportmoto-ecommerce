<?php
// app/helpers/PaginationHelper.php

class PaginationHelper {

    private int    $total;
    private int    $perPage;
    private int    $currentPage;
    private string $baseUrl;

    public function __construct(int $total, int $currentPage, string $baseUrl = '',
                                int $perPage = ITEMS_PER_PAGE) {
        $this->total       = $total;
        $this->perPage     = $perPage;
        $this->currentPage = max(1, $currentPage);
        $this->baseUrl     = $baseUrl;
    }

    // ── Getter público (estava faltando) ──────────────────────
    public function getPerPage(): int {
        return $this->perPage;
    }

    public function totalPages(): int {
        return (int) ceil($this->total / $this->perPage);
    }

    public function offset(): int {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public function hasPages(): bool {
        return $this->total > $this->perPage;
    }

    public function previousPage(): ?int {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    public function nextPage(): ?int {
        return $this->currentPage < $this->totalPages() ? $this->currentPage + 1 : null;
    }

    public function pages(): array {
        $total   = $this->totalPages();
        $current = $this->currentPage;

        if ($total <= 7) {
            return range(1, $total);
        }

        $pages   = [1];
        if ($current > 4) $pages[] = '...';
        $start = max(2, $current - 2);
        $end   = min($total - 1, $current + 2);
        for ($i = $start; $i <= $end; $i++) $pages[] = $i;
        if ($current < $total - 3) $pages[] = '...';
        $pages[] = $total;

        return $pages;
    }

    public function url(int $page): string {
        $params = $_GET;
        $params['pagina'] = $page;
        $query  = http_build_query($params);
        return $this->baseUrl . ($query ? '?' . $query : '');
    }

    public function toArray(): array {
        return [
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'total_pages'  => $this->totalPages(),
            'offset'       => $this->offset(),
            'has_pages'    => $this->hasPages(),
            'prev'         => $this->previousPage(),
            'next'         => $this->nextPage(),
            'pages'        => $this->pages(),
            'pagination'   => $this,
        ];
    }
}