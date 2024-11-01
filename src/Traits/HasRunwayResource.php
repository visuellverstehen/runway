<?php

namespace StatamicRadPack\Runway\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Statamic\Contracts\Data\Augmented;
use Statamic\Contracts\Revisions\Revision;
use Statamic\Facades\Antlers;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Hidden;
use Statamic\Fieldtypes\Section;
use Statamic\GraphQL\ResolvesValues;
use Statamic\Revisions\Revisable;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\Traits\FluentlyGetsAndSets;
use StatamicRadPack\Runway\Data\AugmentedModel;
use StatamicRadPack\Runway\Data\HasAugmentedInstance;
use StatamicRadPack\Runway\Fieldtypes\HasManyFieldtype;
use StatamicRadPack\Runway\Relationships;
use StatamicRadPack\Runway\Resource;
use StatamicRadPack\Runway\Runway;

trait HasRunwayResource
{
    use FluentlyGetsAndSets, HasAugmentedInstance, Revisable;
    use ResolvesValues {
        resolveGqlValue as traitResolveGqlValue;
    }

    public array $runwayRelationships = [];

    public function newAugmentedInstance(): Augmented
    {
        return new AugmentedModel($this);
    }

    public function shallowAugmentedArrayKeys()
    {
        return [$this->runwayResource()->primaryKey(), $this->runwayResource()->titleField(), 'api_url'];
    }

    public function runwayResource(): Resource
    {
        return Runway::findResourceByModel($this);
    }

    public function reference(): string
    {
        return "runway::{$this->runwayResource()->handle()}::{$this->getKey()}";
    }

    public function scopeRunwaySearch(Builder $query, string $searchQuery)
    {
        $this->runwayResource()->blueprint()->fields()->all()
            ->reject(function (Field $field) {
                return $field->fieldtype() instanceof HasManyFieldtype
                    || $field->fieldtype() instanceof Hidden
                    || $field->fieldtype() instanceof Section
                    || $field->visibility() === 'computed';
            })
            ->each(fn (Field $field) => $query->orWhere($field->handle(), 'LIKE', '%'.$searchQuery.'%'));
    }

    public function publishedStatus(): ?string
    {
        if (! $this->runwayResource()->hasPublishStates()) {
            return null;
        }

        if (! $this->published()) {
            return 'draft';
        }

        return 'published';
    }

    public function scopeWhereStatus(Builder $query, string $status): void
    {
        if (! $this->runwayResource()->hasPublishStates()) {
            return;
        }

        switch ($status) {
            case 'published':
                $query->where($this->runwayResource()->publishedColumn(), true);
                break;
            case 'draft':
                $query->where($this->runwayResource()->publishedColumn(), false);
                break;
            case 'scheduled':
                throw new \Exception("Runway doesn't currently support the [scheduled] status.");
            case 'expired':
                throw new \Exception("Runway doesn't currently support the [expired] status.");
            default:
                throw new \Exception("Invalid status [$status]");
        }
    }

    public function resolveGqlValue($field)
    {
        if ($this->runwayResource()->handle() && $field === 'status') {
            return $this->publishedStatus();
        }

        return $this->traitResolveGqlValue($field);
    }

    public function runwayEditUrl(): string
    {
        return cp_route('runway.update', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayLivePreviewUrl(): string
    {
        return cp_route('runway.preview', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayUpdateUrl(): string
    {
        return cp_route('runway.update', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayPublishUrl(): string
    {
        return cp_route('runway.published.store', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayUnpublishUrl(): string
    {
        return cp_route('runway.published.destroy', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayRevisionsUrl(): string
    {
        return cp_route('runway.revisions.index', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayRevisionUrl(Revision $revision): string
    {
        return cp_route('runway.revisions.show', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
            'revisionId' => $revision->id(),
        ]);
    }

    public function runwayRestoreRevisionUrl(): string
    {
        return cp_route('runway.restore-revision', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    public function runwayCreateRevisionUrl(): string
    {
        return cp_route('runway.revisions.store', [
            'resource' => $this->runwayResource()->handle(),
            'model' => $this->{$this->runwayResource()->routeKey()},
        ]);
    }

    protected function revisionKey(): string
    {
        return vsprintf('resources/%s/%s', [
            $this->runwayResource()->handle(),
            $this->getKey(),
        ]);
    }

    protected function revisionAttributes(): array
    {
        $data = $this->runwayResource()->blueprint()->fields()->setParent($this)->all()
            ->reject(fn (Field $field) => $field->fieldtype() instanceof Section)
            ->reject(fn (Field $field) => $field->visibility() === 'computed')
            ->reject(fn (Field $field) => $field->get('save', true) === false)
            ->unique(fn (Field $field) => Str::before($field->handle(), '->'))
            ->mapWithKeys(function (Field $field) {
                $handle = Str::before($field->handle(), '->');

                if ($field->fieldtype() instanceof HasManyFieldtype) {
                    return [$handle => Arr::get($this->runwayRelationships, $handle, [])];
                }

                return [$handle => $this->getAttribute($handle)];
            })
            ->all();

        return [
            'id' => $this->getKey(),
            'published' => $this->published(),
            'data' => $data,
        ];
    }

    public function previewTargets()
    {
        return collect([
            [
                'format' => '{permalink}',
                'label' => 'Entry',
                'refresh' => true,
                'url' => $this->resolvePreviewTargetUrl('{permalink}'),
            ]
        ]);
    }

    private function resolvePreviewTargetUrl($format)
    {
        if (! Str::contains($format, '{{')) {
            $format = preg_replace_callback('/{\s*([a-zA-Z0-9_\-\:\.]+)\s*}/', function ($match) {
                return "{{ {$match[1]} }}";
            }, $format);
        }

        return (string) Antlers::parse($format, array_merge($this->routeData(), [
            'config' => config()->all(),
            // 'site' => $this->site(),
            'uri' => $this->uri(),
            'url' => $this->url(),
            'permalink' => $this->absoluteUrl(),
            // 'locale' => $this->locale(),
        ]));
    }

    public function makeFromRevision($revision): self
    {
        $model = clone $this;

        if (! $revision) {
            return $model;
        }

        $attrs = $revision->attributes();

        $model->published($attrs['published']);

        $blueprint = $this->runwayResource()->blueprint();

        collect($attrs['data'])->each(function ($value, $key) use (&$model, $blueprint) {
            $field = $blueprint->field($key);

            if ($field?->fieldtype() instanceof HasManyFieldtype) {
                $model->runwayRelationships[$key] = $value;

                return;
            }

            $model->setAttribute($key, $value);
        });

        return $model;
    }

    public function revisionsEnabled(): bool
    {
        return $this->runwayResource()->revisionsEnabled();
    }

    public function published($published = null)
    {
        if (! $this->runwayResource()->hasPublishStates()) {
            return func_num_args() === 0 ? null : $this;
        }

        if (func_num_args() === 0) {
            return (bool) $this->getAttribute($this->runwayResource()->publishedColumn());
        }

        $this->setAttribute($this->runwayResource()->publishedColumn(), $published);

        return $this;
    }

    public function publish($options = [])
    {
        if ($this->revisionsEnabled()) {
            $model = $this->publishWorkingCopy($options);

            Relationships::for($model)->with($model->runwayRelationships)->save();

            return $model;
        }

        if ($this->runwayResource()->hasPublishStates()) {
            $saved = $this->published(true)->save();

            if (! $saved) {
                return false;
            }
        }

        return $this;
    }

    public function unpublish($options = [])
    {
        if ($this->revisionsEnabled()) {
            return $this->unpublishWorkingCopy($options);
        }

        if ($this->runwayResource()->hasPublishStates()) {
            $saved = $this->published(false)->save();

            if (! $saved) {
                return false;
            }
        }

        return $this;
    }

    /**
     * We don't need to do anything here, since:
     * - The updated_at timestamp is updated automatically by the database.
     * - We don't have an updated_by column to store the user who last modified the model.
     *
     * @return $this
     */
    public function updateLastModified($user = false): self
    {
        return $this;
    }
}
