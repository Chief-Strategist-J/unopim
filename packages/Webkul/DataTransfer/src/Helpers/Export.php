<?php

namespace Webkul\DataTransfer\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\DataTransfer\Contracts\JobTrack as JobTrackContract;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;

class Export
{
    /**
     * export state for pending export
     */
    public const STATE_PENDING = 'pending';

    /**
     * export state for validated export
     */
    public const STATE_VALIDATED = 'validated';

    /**
     * export state for processing export
     */
    public const STATE_PROCESSING = 'processing';

    /**
     * export state for processed export
     */
    public const STATE_PROCESSED = 'processed';

    /**
     * export state for linking export
     */
    public const STATE_LINKING = 'linking';

    /**
     * export state for linked export
     */
    public const STATE_LINKED = 'linked';

    /**
     * export state for indexing export
     */
    public const STATE_INDEXING = 'indexing';

    /**
     * export state for indexed export
     */
    public const STATE_INDEXED = 'indexed';

    /**
     * export state for completed export
     */
    public const STATE_COMPLETED = 'completed';

    /**
     * export state for failed export
     */
    public const STATE_FAILED = 'failed';

    /**
     * Validation strategy for skipping the error during the export process
     */
    public const VALIDATION_STRATEGY_SKIP_ERRORS = 'skip-errors';

    /**
     * Validation strategy for stopping the export process on error
     */
    public const VALIDATION_STRATEGY_STOP_ON_ERROR = 'stop-on-errors';

    /**
     * Action constant for updating/creating for the resource
     */
    public const ACTION_APPEND = 'append';

    /**
     * Action constant for deleting the resource
     */
    public const ACTION_DELETE = 'delete';

    /**
     * JobTrackContract instance.
     */
    protected JobTrackContract $export;

    /**
     * Error helper instance.
     *
     * @var \Webkul\DataTransfer\Helpers\Error
     */
    protected $typeExporter;

    /**
     * Resource for data read
     */
    protected $source;

    /**
     * Create a new helper instance.
     *
     * @return void
     */
    public function __construct(
        protected JobTrackRepository $jobTrackRepository,
        protected JobTrackBatchRepository $jobTrackBatchRepository,
        protected Error $errorHelper
    ) {}

    /**
     * Set export instance.
     */
    public function setExport(JobTrackContract $export): self
    {
        $this->export = $export;

        return $this;
    }

    /**
     * Returns export instance.
     */
    public function getExport(): JobTrackContract
    {
        return $this->export;
    }

    /**
     * Returns error helper instance.
     *
     * @return \Webkul\DataTransfer\Helpers\Error
     */
    public function getErrorHelper()
    {
        return $this->errorHelper;
    }

    /**
     * Starts import process
     */
    public function isValid(): bool
    {
        return true;
    }

    public function stateUpdate($state = self::STATE_VALIDATED): Export
    {
        $export = $this->jobTrackRepository->update([
            'state' => $state,
        ], $this->export->id);

        $this->setExport($export);

        return $this;
    }

    /**
     * Started the import process
     */
    public function started(): void
    {
        $export = $this->jobTrackRepository->update([
            'state'      => self::STATE_PROCESSING,
            'started_at' => now(),
            'summary'    => [],
        ], $this->export->id);

        $this->setExport($export);

        Event::dispatch('data_transfer.exports.started', $export);

        $typeExporter = $this->getTypeExporter()->setSource($this->source);

        $typeExporter->initializeBatches();
    }

    /**
     * Starts import process
     */
    public function start(?JobTrackBatchContract $exportBatch = null): bool
    {
        DB::beginTransaction();

        try {
            $typeExporter = $this->getTypeExporter();
            $typeExporter->exportData($exportBatch);
        } catch (\Exception $e) {
            /**
             * Rollback transaction
             */
            DB::rollBack();

            throw $e;
        } finally {
            /**
             * Commit transaction
             */
            DB::commit();
        }

        return true;
    }

    /**
     * Start the import process
     */
    public function completed(): void
    {
        $summary = $this->jobTrackBatchRepository
            ->select(
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."processed"\'))) AS processed'),
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."created"\'))) AS created'),
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."skipped"\'))) AS skipped'),
            )
            ->where('job_track_id', $this->export->id)
            ->groupBy('job_track_id')
            ->first()?->toArray();

        if ($summary) {

            $export = $this->jobTrackRepository->update([
                'state'        => self::STATE_COMPLETED,
                'summary'      => $summary,
                'completed_at' => now(),
            ], $this->export->id);

            $this->setExport($export);
            Event::dispatch('data_transfer.export.completed', $export);
        }

    }

    /**
     * Returns import stats
     */
    public function stats(string $state): array
    {
        $total = $this->export->batches->count();
        $completed = $this->export->batches->where('state', $state)->count();

        $progress = $total
            ? round($completed / $total * 100)
            : 0;

        $summary = $this->jobTrackBatchRepository
            ->select(
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."processed"\'))) AS processed'),
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."created"\'))) AS created'),
                DB::raw('SUM(json_unquote(json_extract(summary, \'$."skipped"\'))) AS skipped'),
            )
            ->where('job_track_id', $this->export->id)
            ->where('state', $state)
            ->groupBy('job_track_id')
            ->first()
            ?->toArray();

        return [
            'batches' => [
                'total'     => $total,
                'completed' => $completed,
                'remaining' => $total - $completed,
            ],
            'progress' => $progress,
            'summary'  => $summary ?? [
                'processed' => 0,
                'created'   => 0,
                'skipped'   => 0,
            ],
        ];
    }

    /**
     * Start the import process
     */
    public function uploadFile(string $filePath, string $temporaryPath, array $filters): void
    {
        $withMedia = (bool) $filters['with_media'];
        $filePath = $withMedia ? $temporaryPath : $filePath;
        $export = $this->jobTrackRepository->update([
            'file_path' => $filePath,
        ], $this->export->id);
    }

    /**
     * Validates source file and returns validation result
     */
    public function getTypeExporter(): AbstractExporter
    {
        $jobInstance = $this->export->jobInstance;

        if (! $this->typeExporter) {
            $exporterConfig = config('exporters.'.$jobInstance->entity_type);

            $this->typeExporter = app()->make($exporterConfig['exporter'])
                ->setExport($this->export)
                ->setErrorHelper($this->errorHelper);

            $this->source = app()->make($exporterConfig['source']);
        }

        return $this->typeExporter;
    }
}