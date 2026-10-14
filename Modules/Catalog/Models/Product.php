<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Database\Factories\ProductFactory;
use Modules\Settings\Models\Company;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Producto del catálogo.
 *
 * Concentra identificación, precios, control de stock y estado. El stock real por
 * almacén vive en el módulo Inventario (relación `stocks`), calculado on-demand.
 */
class Product extends Model implements Auditable
{
    use HasFactory;
    use SoftDeletes;
    use AuditableTrait;

    /** Bien físico: lleva stock por almacén. */
    public const TYPE_PRODUCT = 'product';

    /** Servicio (facial, corporal…): se vende pero no lleva stock. */
    public const TYPE_SERVICE = 'service';

    /**
     * Paquete de sesiones: lo que el POS vende de un paquete del módulo
     * Paquetes. Lo crea y mantiene ese módulo; no se edita como producto.
     */
    public const TYPE_PACKAGE = 'package';

    /** Tipos que se crean desde el catálogo (el paquete nace en su módulo). */
    public const TYPES = [self::TYPE_PRODUCT, self::TYPE_SERVICE];

    protected $fillable = [
        'company_id', 'category_id', 'brand_id', 'unit_id', 'type',
        'code', 'barcode', 'sku', 'name', 'description', 'image_path', 'qr_path',
        'cost', 'price', 'wholesale_price', 'offer_price',
        'stock_min', 'stock_max', 'track_stock', 'has_expiry', 'is_active',
        // Lo que muestra la web pública (con la imagen y la descripción).
        'web_published', 'duration_minutes',
        // En promoción: sale en «Promociones» de la web y cobra `offer_price` si lo tiene.
        'on_promotion',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'offer_price' => 'decimal:2',
        'stock_min' => 'decimal:2',
        'stock_max' => 'decimal:2',
        'track_stock' => 'boolean',
        'has_expiry' => 'boolean',
        'is_active' => 'boolean',
        'web_published' => 'boolean',
        'on_promotion' => 'boolean',
        'duration_minutes' => 'integer',
    ];

    protected $appends = ['image_url', 'profit_margin', 'current_stock'];

    protected $attributes = [
        'type' => self::TYPE_PRODUCT,
        'on_promotion' => false,
    ];

    /** Solo un bien físico controla stock, venga de donde venga el cambio. */
    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->type !== self::TYPE_PRODUCT) {
                $product->track_stock = false;
            }
        });
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function isPackage(): bool
    {
        return $this->type === self::TYPE_PACKAGE;
    }

    /**
     * Precio de promoción vigente: el de oferta, solo si está en promoción y
     * de verdad rebaja el precio. Si no, null (se cobra el precio normal).
     */
    public function promotionPrice(): ?float
    {
        $offer = $this->offer_price !== null ? (float) $this->offer_price : null;

        return $this->on_promotion && $offer !== null && $offer > 0 && $offer < (float) $this->price ? $offer : null;
    }

    /** Lo que se cobra hoy: el de promoción si lo hay; si no, el normal. */
    public function salePrice(): float
    {
        return $this->promotionPrice() ?? (float) $this->price;
    }

    // --- Relaciones -------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** Fotos adicionales para la ficha de la web, en el orden en que se subieron. */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Existencias por almacén (módulo Inventario). */
    public function stocks(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Stock::class);
    }

    // --- Accessors --------------------------------------------------------

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    /**
     * Stock total del producto sumando todos los almacenes. Usa `stocks_sum_quantity`
     * si el repositorio lo cargó con withSum (evita N+1); si no, lo calcula.
     */
    public function getCurrentStockAttribute(): float
    {
        if (array_key_exists('stocks_sum_quantity', $this->attributes)) {
            return (float) ($this->attributes['stocks_sum_quantity'] ?? 0);
        }

        if ($this->relationLoaded('stocks')) {
            return (float) $this->stocks->sum('quantity');
        }

        return (float) $this->stocks()->sum('quantity');
    }

    /** Margen de utilidad % sobre el precio de venta (0 si no hay precio). */
    public function getProfitMarginAttribute(): float
    {
        $price = (float) $this->price;
        if ($price <= 0) {
            return 0.0;
        }

        return round((($price - (float) $this->cost) / $price) * 100, 2);
    }

    // --- Scopes -----------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
