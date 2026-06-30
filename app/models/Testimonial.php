<?php
// app/models/Testimonial.php

class Testimonial extends Model {
    protected string $table = 'depoimentos';

    public function getApproved(int $limit = 6): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM depoimentos
             WHERE aprovado = 1
             ORDER BY ordem ASC, criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}