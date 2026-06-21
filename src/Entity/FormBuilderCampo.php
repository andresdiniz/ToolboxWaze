<?php

namespace App\Entity;

use App\Repository\FormBuilderCampoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipos suportados:
 *  text | textarea | number | email | url | date | datetime | time
 *  select | select_multiple | radio | checkbox | toggle
 *  file | image
 *  hidden | html (bloco livre de HTML/texto)
 *  divider | heading (layout)
 */
#[ORM\Entity(repositoryClass: FormBuilderCampoRepository::class)]
#[ORM\Table(name: 'form_builder_campo')]
class FormBuilderCampo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'campos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormBuilder $formulario = null;

    /** Identificador interno do campo (usado como chave na resposta JSON) */
    #[ORM\Column(length: 80)]
    private string $chave = '';

    #[ORM\Column(length: 120)]
    private string $label = '';

    /** text | textarea | number | email | url | date | datetime | time | select | select_multiple | radio | checkbox | toggle | file | image | hidden | html | divider | heading */
    #[ORM\Column(length: 30)]
    private string $tipo = 'text';

    #[ORM\Column]
    private int $ordem = 0;

    #[ORM\Column]
    private bool $obrigatorio = false;

    /** Placeholder / texto de ajuda */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeholder = null;

    /** Texto de ajuda exibido abaixo do campo */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $ajuda = null;

    /**
     * Configurações extras em JSON:
     * - opcoes: array de strings (para select/radio/checkbox)
     * - min, max, step (number/date)
     * - accept (file/image: 'image/*', '.pdf', etc.)
     * - multiple (file)
     * - mask (text: '99/99/9999', 'cpf', 'cnpj', 'telefone', 'cep')
     * - validacao: regex pattern
     * - condicional: { campo: 'chave', operador: '=|!=|>|<|contains', valor: '...' }
     * - css_class: classe extra no wrapper
     * - col: 1-12 (grid bootstrap)
     * - content: conteúdo HTML para tipo 'html'
     * - nivel: 1-6 para tipo 'heading'
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $opcoes = null;

    /** Valor padrão */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $valorPadrao = null;

    // ── getters / setters ──────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getFormulario(): ?FormBuilder { return $this->formulario; }
    public function setFormulario(?FormBuilder $formulario): static { $this->formulario = $formulario; return $this; }

    public function getChave(): string { return $this->chave; }
    public function setChave(string $chave): static { $this->chave = $chave; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): static { $this->tipo = $tipo; return $this; }

    public function getOrdem(): int { return $this->ordem; }
    public function setOrdem(int $ordem): static { $this->ordem = $ordem; return $this; }

    public function isObrigatorio(): bool { return $this->obrigatorio; }
    public function setObrigatorio(bool $obrigatorio): static { $this->obrigatorio = $obrigatorio; return $this; }

    public function getPlaceholder(): ?string { return $this->placeholder; }
    public function setPlaceholder(?string $placeholder): static { $this->placeholder = $placeholder; return $this; }

    public function getAjuda(): ?string { return $this->ajuda; }
    public function setAjuda(?string $ajuda): static { $this->ajuda = $ajuda; return $this; }

    public function getOpcoes(): ?array { return $this->opcoes; }
    public function setOpcoes(?array $opcoes): static { $this->opcoes = $opcoes; return $this; }

    public function getValorPadrao(): ?string { return $this->valorPadrao; }
    public function setValorPadrao(?string $valorPadrao): static { $this->valorPadrao = $valorPadrao; return $this; }
}
