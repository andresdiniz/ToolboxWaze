-- Referência para: php bin/console doctrine:migrations:diff

CREATE TABLE solicitacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pendente',
    solicitante_nome VARCHAR(255) NOT NULL,
    solicitante_usuario VARCHAR(255) NOT NULL,
    solicitante_email VARCHAR(255) NOT NULL,
    estado VARCHAR(2) DEFAULT NULL,
    dados JSON DEFAULT NULL,
    arquivos JSON DEFAULT NULL,
    resolvida_por_id INT DEFAULT NULL,
    resolvida_em DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    nota_resolucao LONGTEXT DEFAULT NULL,
    criada_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    atualizada_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_status (status),
    INDEX idx_tipo (tipo),
    CONSTRAINT fk_sol_resolvida_por FOREIGN KEY (resolvida_por_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tipo_solicitacao_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(64) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tipo_solicitacao_responsaveis (
    tipo_solicitacao_config_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (tipo_solicitacao_config_id, user_id),
    CONSTRAINT fk_tsr_config FOREIGN KEY (tipo_solicitacao_config_id) REFERENCES tipo_solicitacao_config(id),
    CONSTRAINT fk_tsr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE solicitacao_responsaveis (
    solicitacao_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (solicitacao_id, user_id),
    CONSTRAINT fk_sr_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
    CONSTRAINT fk_sr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
