<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115150000 extends AbstractMigration
{
    private const DEFINICOES = <<<'JSON'
{
  "imagem_satelite": [
    {
      "chave": "motivo",
      "label": "Motivo da solicitação",
      "tipo": "select",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "muito_antiga",
            "label": "A imagem com maior resolução é muito antiga"
          },
          {
            "value": "baixa_qualidade",
            "label": "A imagem está com qualidade/resolução baixa"
          },
          {
            "value": "alteracoes",
            "label": "Alterações significativas na área"
          },
          {
            "value": "ruins_nubladas",
            "label": "As imagens estão ruins ou nubladas"
          }
        ]
      },
      "ordem": 10,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "idadeImagem",
      "label": "Quão antiga pode ser a imagem atualizada?",
      "tipo": "select",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "1_ano",
            "label": "1 ano"
          },
          {
            "value": "6_meses",
            "label": "6 meses"
          },
          {
            "value": "1_mes",
            "label": "1 mês"
          },
          {
            "value": "1_semana",
            "label": "1 semana"
          }
        ]
      },
      "ordem": 20,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "urgente",
      "label": "É urgente?",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "sim",
            "label": "Sim"
          },
          {
            "value": "nao",
            "label": "Não"
          }
        ]
      },
      "ordem": 30,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "permalink",
      "label": "Permalink (zoom ≥15)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "validacao": "permalink",
        "zoomMinimo": 15,
        "preenchimento": {
          "origem": "wme.permalink"
        }
      },
      "ordem": 40,
      "placeholder": "https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15",
      "ajuda": null
    }
  ],
  "gerente_area": [
    {
      "chave": "mentor",
      "label": "Quem foi seu mentor?",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {},
      "ordem": 10,
      "placeholder": null,
      "ajuda": "Digite o nome do usuário do seu mentor."
    },
    {
      "chave": "recomendadores",
      "label": "Quais editores que podem recomendar sua aptidão à função?",
      "tipo": "text",
      "obrigatorio": false,
      "opcoes": {},
      "ordem": 20,
      "placeholder": null,
      "ajuda": "Escolha os editores que podem recomendar você."
    },
    {
      "chave": "comentarios",
      "label": "Comentários:",
      "tipo": "textarea",
      "obrigatorio": false,
      "opcoes": {
        "rows": 3
      },
      "ordem": 30,
      "placeholder": null,
      "ajuda": "Caso necessário, adicione informações relevantes para a sua solicitação."
    },
    {
      "chave": "poligono",
      "label": "Insira o polígono:",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "validacao": "wkt",
        "rows": 4
      },
      "ordem": 40,
      "placeholder": null,
      "ajuda": "Cole o polígono da área, iniciado com POLYGON(( ou LINESTRING(. Use https://arthur-e.github.io/Wicket/sandbox-gmaps3.html ou http://map.wazedev.com/."
    },
    {
      "chave": "comunicouIntencao",
      "label": "Comuniquei minha intenção.",
      "tipo": "checkbox",
      "obrigatorio": true,
      "opcoes": {
        "valorObrigatorio": true,
        "atributos_site": {
          "data-discuss-gate": "1"
        },
        "requerVisitaLink": true
      },
      "ordem": 50,
      "placeholder": null,
      "ajuda": "Comunique sua intenção no Discuss do Estado, no tópico \"Gerente de área ou candidato, se apresente aqui\". https://www.waze.com/discuss/c/editors/brasil-estados/4114"
    }
  ],
  "gerente_estado_pais": [
    {
      "chave": "acaoGerenteEstadoPais",
      "label": "Você deseja…",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "incluir",
            "label": "Incluir"
          },
          {
            "value": "excluir",
            "label": "Excluir"
          }
        ],
        "permissoesOpcoes": {
          "excluir": [
            "ROLE_CHAMP"
          ]
        }
      },
      "ordem": 10,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "souChampEp",
      "label": "Confirmo que sou Champ e estou autorizado a solicitar a exclusão deste Gerente",
      "tipo": "checkbox",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "acaoGerenteEstadoPais": "excluir"
        },
        "roles": [
          "ROLE_CHAMP"
        ],
        "valorObrigatorio": true
      },
      "ordem": 20,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "mentor",
      "label": "Mentor",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "acaoGerenteEstadoPais": "incluir"
        }
      },
      "ordem": 30,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "recomendadores",
      "label": "Editores recomendadores",
      "tipo": "text",
      "obrigatorio": false,
      "opcoes": {
        "mostrarSe": {
          "acaoGerenteEstadoPais": "incluir"
        }
      },
      "ordem": 40,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "editorNome",
      "label": "Nome de usuário do Gerente (a ser removido)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "acaoGerenteEstadoPais": "excluir"
        },
        "maxLength": 255,
        "roles": [
          "ROLE_CHAMP"
        ]
      },
      "ordem": 50,
      "placeholder": "Nick do editor no WME/Discuss",
      "ajuda": null
    },
    {
      "chave": "cargo",
      "label": "Cargo desejado",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "gerente_estado",
            "label": "Gerente de Estado"
          },
          {
            "value": "gerente_pais",
            "label": "Gerente de País"
          }
        ],
        "mostrarSe": {
          "acaoGerenteEstadoPais": [
            "incluir",
            "excluir"
          ]
        },
        "variantes": [
          {
            "quando": {
              "acaoGerenteEstadoPais": "excluir"
            },
            "label": "Cargo a ser removido"
          }
        ]
      },
      "ordem": 60,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "uf",
      "label": "Estado",
      "tipo": "select",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "AC",
            "label": "AC"
          },
          {
            "value": "AL",
            "label": "AL"
          },
          {
            "value": "AM",
            "label": "AM"
          },
          {
            "value": "AP",
            "label": "AP"
          },
          {
            "value": "BA",
            "label": "BA"
          },
          {
            "value": "CE",
            "label": "CE"
          },
          {
            "value": "DF",
            "label": "DF"
          },
          {
            "value": "ES",
            "label": "ES"
          },
          {
            "value": "GO",
            "label": "GO"
          },
          {
            "value": "MA",
            "label": "MA"
          },
          {
            "value": "MG",
            "label": "MG"
          },
          {
            "value": "MS",
            "label": "MS"
          },
          {
            "value": "MT",
            "label": "MT"
          },
          {
            "value": "PA",
            "label": "PA"
          },
          {
            "value": "PB",
            "label": "PB"
          },
          {
            "value": "PE",
            "label": "PE"
          },
          {
            "value": "PI",
            "label": "PI"
          },
          {
            "value": "PR",
            "label": "PR"
          },
          {
            "value": "RJ",
            "label": "RJ"
          },
          {
            "value": "RN",
            "label": "RN"
          },
          {
            "value": "RO",
            "label": "RO"
          },
          {
            "value": "RR",
            "label": "RR"
          },
          {
            "value": "RS",
            "label": "RS"
          },
          {
            "value": "SC",
            "label": "SC"
          },
          {
            "value": "SE",
            "label": "SE"
          },
          {
            "value": "SP",
            "label": "SP"
          },
          {
            "value": "TO",
            "label": "TO"
          }
        ],
        "mostrarSe": {
          "cargo": "gerente_estado"
        },
        "variantes": [
          {
            "quando": {
              "acaoGerenteEstadoPais": "excluir"
            },
            "label": "Estado do gerente"
          }
        ]
      },
      "ordem": 70,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "justificativa",
      "label": "Justificativa",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 20,
        "maxLength": 2000,
        "mostrarSe": {
          "acaoGerenteEstadoPais": "excluir"
        },
        "roles": [
          "ROLE_CHAMP"
        ]
      },
      "ordem": 80,
      "placeholder": "Descreva o motivo da remoção (mínimo 20 caracteres)",
      "ajuda": "Informe o motivo pelo qual o Gerente deve ser removido do cargo."
    },
    {
      "chave": "comentarios",
      "label": "Comentários",
      "tipo": "textarea",
      "obrigatorio": false,
      "opcoes": {
        "mostrarSe": {
          "acaoGerenteEstadoPais": [
            "incluir",
            "excluir"
          ]
        },
        "rows": 3,
        "variantes": [
          {
            "quando": {
              "acaoGerenteEstadoPais": "excluir"
            },
            "label": "Comentários adicionais"
          }
        ]
      },
      "ordem": 90,
      "placeholder": null,
      "ajuda": null
    }
  ],
  "nivel": [
    {
      "chave": "tipoNivel",
      "label": "É um pedido de…",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "upgrade",
            "label": "Upgrade"
          },
          {
            "value": "downgrade",
            "label": "Downgrade"
          }
        ],
        "permissoesOpcoes": {
          "downgrade": [
            "ROLE_CHAMP"
          ]
        }
      },
      "ordem": 10,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "souChamp",
      "label": "Confirmo que sou Champ e estou autorizado a solicitar downgrade",
      "tipo": "checkbox",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": "downgrade"
        },
        "roles": [
          "ROLE_CHAMP"
        ],
        "valorObrigatorio": true
      },
      "ordem": 20,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "mentor",
      "label": "Mentor",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": "upgrade"
        }
      },
      "ordem": 30,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "recomendadores",
      "label": "Editores recomendadores",
      "tipo": "text",
      "obrigatorio": false,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": [
            "upgrade",
            "downgrade"
          ]
        },
        "variantes": [
          {
            "quando": {
              "tipoNivel": "downgrade"
            },
            "label": "Champs ou editores que corroboram",
            "placeholder": "Nicks separados por vírgula (opcional)",
            "ajuda": "Outros Champs ou editores de referência que concordam com a solicitação."
          }
        ]
      },
      "ordem": 40,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "editorNome",
      "label": "Nome de usuário do editor (alvo do downgrade)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": "downgrade"
        },
        "roles": [
          "ROLE_CHAMP"
        ],
        "maxLength": 255
      },
      "ordem": 50,
      "placeholder": "Nick do editor no WME/Discuss",
      "ajuda": null
    },
    {
      "chave": "nivelAtual",
      "label": "Nível atual",
      "tipo": "number",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": [
            "upgrade",
            "downgrade"
          ]
        },
        "min": 1,
        "max": 5,
        "variantes": [
          {
            "quando": {
              "tipoNivel": "downgrade"
            },
            "min": 2,
            "max": 6,
            "label": "Nível atual do editor",
            "placeholder": "Nível atual do editor (2 a 6)",
            "ajuda": "Nível atual do editor que receberá o downgrade."
          }
        ]
      },
      "ordem": 60,
      "placeholder": "Digite o seu nível de um a cinco",
      "ajuda": "Digite o seu nível de um a cinco."
    },
    {
      "chave": "nivelDesejado",
      "label": "Nível desejado",
      "tipo": "number",
      "obrigatorio": true,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": [
            "upgrade",
            "downgrade"
          ]
        },
        "min": 2,
        "max": 6,
        "variantes": [
          {
            "quando": {
              "tipoNivel": "downgrade"
            },
            "min": 1,
            "max": 5,
            "label": "Nível para o qual será rebaixado",
            "placeholder": "Nível alvo (1 a 5)",
            "ajuda": "O nível resultante após o downgrade."
          }
        ]
      },
      "ordem": 70,
      "placeholder": "Digite o nível desejado de dois a seis",
      "ajuda": "Digite o nível desejado de dois a seis."
    },
    {
      "chave": "ativo",
      "label": "Você se considera ativo na comunidade?",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "sim",
            "label": "Sim"
          },
          {
            "value": "nao",
            "label": "Não"
          }
        ],
        "mostrarSe": {
          "tipoNivel": "upgrade"
        }
      },
      "ordem": 80,
      "placeholder": null,
      "ajuda": "Indique se você se considera ativo nos canais oficiais do Waze."
    },
    {
      "chave": "postagens",
      "label": "Quantas postagens você fez no último ano no Discuss?",
      "tipo": "select",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "1_5",
            "label": "Entre 1 e 5 postagens"
          },
          {
            "value": "6_10",
            "label": "Entre 6 e 10 postagens"
          },
          {
            "value": "11_20",
            "label": "Entre 11 e 20 postagens"
          },
          {
            "value": "21_50",
            "label": "Entre 21 e 50 postagens"
          },
          {
            "value": "50_mais",
            "label": "Mais de 50 postagens"
          },
          {
            "value": "nenhuma",
            "label": "Nenhuma."
          }
        ],
        "mostrarSe": {
          "tipoNivel": "upgrade"
        }
      },
      "ordem": 90,
      "placeholder": null,
      "ajuda": "Consulte https://www.waze.com/discuss/u/SEU_NICK/summary"
    },
    {
      "chave": "motivacao",
      "label": "O que motiva você a subir de nível?",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 20,
        "maxLength": 2000,
        "mostrarSe": {
          "tipoNivel": "upgrade"
        }
      },
      "ordem": 100,
      "placeholder": "Descreva sua motivação (mínimo 20 caracteres)",
      "ajuda": "Explique o que o motiva a buscar um nível mais alto."
    },
    {
      "chave": "cumpriuRequisitos",
      "label": "Você cumpre os requisitos para essa promoção?",
      "tipo": "radio",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "sim",
            "label": "Sim"
          },
          {
            "value": "nao",
            "label": "Não"
          }
        ],
        "mostrarSe": {
          "tipoNivel": "upgrade"
        },
        "valorObrigatorio": "sim"
      },
      "ordem": 110,
      "placeholder": null,
      "ajuda": "Leia os requisitos em https://www.waze.com/discuss/t/new-regras-e-normas-para-niveis-cargos-e-areas/282152"
    },
    {
      "chave": "motivoDowngrade",
      "label": "Motivo do downgrade",
      "tipo": "select",
      "obrigatorio": true,
      "opcoes": {
        "opcoes": [
          {
            "value": "inatividade",
            "label": "Inatividade prolongada"
          },
          {
            "value": "edicoes_destrutivas",
            "label": "Edições destrutivas recorrentes"
          },
          {
            "value": "normas",
            "label": "Desrespeito às normas da comunidade"
          },
          {
            "value": "pedido_proprio",
            "label": "Solicitação do próprio editor"
          },
          {
            "value": "outro",
            "label": "Outro"
          }
        ],
        "mostrarSe": {
          "tipoNivel": "downgrade"
        },
        "roles": [
          "ROLE_CHAMP"
        ]
      },
      "ordem": 120,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "justificativa",
      "label": "Justificativa detalhada",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 30,
        "maxLength": 2000,
        "mostrarSe": {
          "tipoNivel": "downgrade"
        },
        "roles": [
          "ROLE_CHAMP"
        ]
      },
      "ordem": 130,
      "placeholder": "Descreva detalhadamente o motivo do downgrade (mínimo 30 caracteres)",
      "ajuda": "Apresente evidências ou descrição clara da situação que justifica o rebaixamento."
    },
    {
      "chave": "comentarios",
      "label": "Comentários adicionais",
      "tipo": "textarea",
      "obrigatorio": false,
      "opcoes": {
        "mostrarSe": {
          "tipoNivel": "downgrade"
        },
        "roles": [
          "ROLE_CHAMP"
        ],
        "rows": 3
      },
      "ordem": 140,
      "placeholder": null,
      "ajuda": null
    }
  ],
  "oops": [
    {
      "chave": "editorNome",
      "label": "Nome de usuário do editor",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "maxLength": 255
      },
      "ordem": 10,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "permalink",
      "label": "Permalink (zoom ≥15)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "validacao": "permalink",
        "zoomMinimo": 15,
        "preenchimento": {
          "origem": "wme.permalink"
        }
      },
      "ordem": 20,
      "placeholder": "https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15",
      "ajuda": null
    },
    {
      "chave": "descricao",
      "label": "Descrição do problema",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 20,
        "maxLength": 2000
      },
      "ordem": 30,
      "placeholder": "Descreva o problema detectado (mínimo 20 caracteres)",
      "ajuda": null
    },
    {
      "chave": "arquivos",
      "label": "Imagens do problema",
      "tipo": "file",
      "obrigatorio": false,
      "opcoes": {
        "maxArquivos": 5,
        "maxBytes": 1048576,
        "mimeTypes": [
          "image/jpeg",
          "image/png",
          "image/webp"
        ],
        "destino": "solicitacao.arquivos",
        "nomeLegado": "arquivos_oops"
      },
      "ordem": 40,
      "placeholder": null,
      "ajuda": null
    }
  ],
  "bandeira_posto": [
    {
      "chave": "nomePosto",
      "label": "Nome do posto",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "maxLength": 255,
        "preenchimento": {
          "origem": "wme.venueName"
        }
      },
      "ordem": 10,
      "placeholder": null,
      "ajuda": null
    },
    {
      "chave": "bandeira",
      "label": "Bandeira",
      "tipo": "text",
      "obrigatorio": false,
      "opcoes": {},
      "ordem": 20,
      "placeholder": "Ex: Petrobras, Shell, Ipiranga…",
      "ajuda": null
    },
    {
      "chave": "permalink",
      "label": "Permalink (zoom ≥15)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "validacao": "permalink",
        "zoomMinimo": 15,
        "preenchimento": {
          "origem": "wme.permalink"
        }
      },
      "ordem": 30,
      "placeholder": "https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15",
      "ajuda": null
    },
    {
      "chave": "descricaoBandeira",
      "label": "Descrição / Justificativa",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 10,
        "maxLength": 2000
      },
      "ordem": 40,
      "placeholder": "Descreva a solicitação (mínimo 10 caracteres)",
      "ajuda": null
    }
  ],
  "id_segmento": [
    {
      "chave": "permalink",
      "label": "Permalink (zoom ≥15)",
      "tipo": "text",
      "obrigatorio": true,
      "opcoes": {
        "validacao": "permalink",
        "zoomMinimo": 15,
        "preenchimento": {
          "origem": "wme.permalink"
        }
      },
      "ordem": 10,
      "placeholder": "https://waze.com/pt-BR/editor?env=row&lat=-20&lon=-43&zoomLevel=15",
      "ajuda": null
    },
    {
      "chave": "descricao",
      "label": "Descrição do segmento",
      "tipo": "textarea",
      "obrigatorio": true,
      "opcoes": {
        "minLength": 10,
        "maxLength": 1000
      },
      "ordem": 20,
      "placeholder": "Descreva brevemente o segmento (mínimo 10 caracteres)",
      "ajuda": null
    }
  ]
}
JSON;

    public function getDescription(): string
    {
        return 'Cadastra campos dos sete tipos; preserva campos existentes e bloqueia WME dos tipos com regras privilegiadas pendentes.';
    }

    public function up(Schema $schema): void
    {
        $definitions = json_decode(self::DEFINICOES, true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($definitions as $slug => $fields) {
            $id = $this->connection->fetchOne('SELECT id FROM form_builder WHERE slug = ?', [$slug]);
            $this->abortIf($id === false, 'Formulario ausente: '.$slug.'. Execute primeiro a migration dos tipos.');
            $ids[$slug] = (int) $id;
        }

        foreach ($definitions as $slug => $fields) {
            foreach ($fields as $field) {
                $count = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM form_builder_campo WHERE formulario_id = ? AND chave = ?',
                    [$ids[$slug], $field['chave']]
                );
                $this->abortIf($count > 1, 'Chave duplicada existente: '.$slug.'.'.$field['chave']);
                if ($count === 1) {
                    $this->write('Preservado campo existente: '.$slug.'.'.$field['chave']);
                    continue;
                }
                $this->addSql(
                    'INSERT INTO form_builder_campo (formulario_id, chave, label, tipo, ordem, obrigatorio, placeholder, ajuda, opcoes, valor_padrao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $ids[$slug], $field['chave'], $field['label'], $field['tipo'],
                        $field['ordem'], (int) $field['obrigatorio'], $field['placeholder'], $field['ajuda'],
                        json_encode((object) $field['opcoes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), null,
                    ]
                );
            }
        }

        // O backend atual ignora permissoesOpcoes, roles e variantes.
        // Nao publicar acoes privilegiadas antes de implementar a validacao compartilhada.
        foreach (['nivel', 'gerente_estado_pais'] as $slug) {
            $raw = $this->connection->fetchOne('SELECT configuracoes FROM form_builder WHERE id = ?', [$ids[$slug]]);
            $config = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            $this->abortIf(!is_array($config), 'Configuracoes invalidas: '.$slug);
            $config['wme_api_enabled'] = false;
            $config['unificacao_campos_pendente'] = true;
            $this->addSql('UPDATE form_builder SET configuracoes = ? WHERE id = ?', [
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $ids[$slug],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Migration de dados: rollback automatico poderia remover campos personalizados ou usados por respostas.');
    }
}
