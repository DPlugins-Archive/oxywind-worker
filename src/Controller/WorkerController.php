<?php

namespace App\Controller;

use App\Entity\Profile;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class WorkerController extends AbstractController
{
    private ValidatorInterface $validator;

    private FilesystemOperator $storage;

    private Uuid $uuid;

    private Stopwatch $stopwatch;

    private ManagerRegistry $doctrine;

    public function __construct(ValidatorInterface $validator, FilesystemOperator $defaultStorage, Stopwatch $stopwatch, ManagerRegistry $doctrine)
    {
        $this->validator = $validator;
        $this->storage = $defaultStorage;
        $this->uuid = Uuid::v4();
        $this->stopwatch = $stopwatch;
        $this->doctrine = $doctrine;
    }

    #[Route('/', name: 'app_worker_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirect('https://tailwindcss.oxyrealm.com', Response::HTTP_FOUND);
    }

    #[Route('/', name: 'app_worker_worker', methods: ['POST'])]
    public function worker(Request $request): Response
    {
        $this->stopwatch->start('tailwindcss-worker');

        $userAgent = $request->headers->get('user-agent');

        $payload = $request->toArray();

        $css = array_key_exists('css', $payload) ? $payload['css'] : '';
        $preset = array_key_exists('preset', $payload) ? $payload['preset'] : '';
        $content = array_key_exists('content', $payload) ? $payload['content'] : '';

        try {
            $this->validateRequest($userAgent, $css, $preset, $content);
        } catch (\Throwable $th) {
            return $this->json([
                'status' => 'error',
                'errors' => $th->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->writeFile($css, $preset, $content);
        } catch (\Throwable $th) {
            return $this->json([
                'status' => 'error',
                'errors' => $th->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $storageDir = $this->getParameter('app.local_storage_dir');

        $process = new Process(
            [
                "/{$projectDir}/bin/tailwindcss",
                '-i', "{$storageDir}/{$this->uuid}/input.css",
                '-c', "{$storageDir}/{$this->uuid}/tailwind.config.js",
                '--minify'
            ],
            "{$storageDir}/{$this->uuid}"
        );

        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json([
                'status' => 'error',
                // 'errors' => str_replace('/home/zenbook', '/container', $process->getErrorOutput()),
                'errors' => $process->getErrorOutput(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $buffer = $process->getOutput();

        $this->stopwatch->stop('tailwindcss-worker');

        $entityManager = $this->doctrine->getManager();

        $userAgent = explode('; ', $userAgent);

        $profile = new Profile();
        $profile->setUuid($this->uuid);
        $profile->setDuration($this->stopwatch->getEvent('tailwindcss-worker')->getDuration());
        $profile->setMemory($this->stopwatch->getEvent('tailwindcss-worker')->getMemory());
        $profile->setTailwindcss($this->getParameter('app.tailwindcss'));
        $profile->setWordpress(str_replace('WordPress/', '', $userAgent[0]));
        $profile->setSite($userAgent[1]);

        $entityManager->persist($profile);
        $entityManager->flush();

        return $this->json([
            'status' => 'success',
            'uuid' => $this->uuid,
            'css' => $buffer,
        ], Response::HTTP_OK);
    }

    private function writeFile($css, $preset, $content)
    {
        try {
            $this->storage->write("{$this->uuid}/input.css", $css);
        } catch (FilesystemException | UnableToWriteFile $exception) {
            throw new Exception('Failed to warm up the compiler [input.css].');
        }

        try {
            $preset = str_replace('tailwind.config = {', 'module.exports = {', $preset);

            $this->storage->write("{$this->uuid}/preset.js", $preset);
        } catch (FilesystemException | UnableToWriteFile $exception) {
            throw new Exception('Failed to warm up the compiler [preset.js].');
        }

        try {
            $this->storage->write("{$this->uuid}/content.html", $content);
        } catch (FilesystemException | UnableToWriteFile $exception) {
            throw new Exception('Failed to warm up the compiler [content.html].');
        }

        $storageDir = $this->getParameter('app.local_storage_dir');

        $defaultPreset = <<<TAILWINDPRESET
        module.exports = {
            content: [
                '{$storageDir}/{$this->uuid}/content.html'
            ],
            presets: [
                require('{$storageDir}/{$this->uuid}/preset.js')
            ],
            plugins: [
                require('@tailwindcss/forms'),
                require('@tailwindcss/typography'),
                require('@tailwindcss/line-clamp'),
            ],
        }
        TAILWINDPRESET;

        try {
            $this->storage->write("{$this->uuid}/tailwind.config.js", $defaultPreset);
        } catch (FilesystemException | UnableToWriteFile $exception) {
            throw new Exception('Failed to warm up the compiler [tailwind.config.js].');
        }
    }

    private function validateRequest($userAgent, $css, $preset, $content)
    {
        $errors = $this->validator->validate($preset, [
            new Assert\Sequentially([
                new Assert\NotBlank([
                    'message' => 'The tailwind config preset is required.',
                ]),
                new Assert\Callback(function ($object, ExecutionContextInterface $context, $payload) {
                    if ('tailwind.config = {' !== trim(explode("\n", $object)[0])) {
                        $context->buildViolation('The tailwind config preset is not following the expected format.')
                            ->atPath('preset')
                            ->addViolation();
                    }
                }),
            ])
        ]);

        if (count($errors) > 0) {
            throw new Exception((string) $errors);
        }

        $errors = $this->validator->validate($content, [
            new Assert\NotBlank([
                'message' => 'The content is required.',
            ])
        ]);

        if (count($errors) > 0) {
            throw new Exception((string) $errors);
        }

        $errors = $this->validator->validate($css, [
            new Assert\NotBlank([
                'message' => 'The css is required.',
            ])
        ]);

        if (count($errors) > 0) {
            throw new Exception((string) $errors);
        }

        $errors = $this->validator->validate($userAgent, [
            new Assert\Sequentially([
                new Assert\NotBlank([
                    'message' => 'The origin of request is unknown.',
                ]),
                new Assert\Callback(function ($object, ExecutionContextInterface $context, $payload) {
                    if (false === strpos($object, 'WordPress/')) {
                        $context->buildViolation('The request is not sent by Oxywind plugins.')
                            ->atPath('origin')
                            ->addViolation();
                    }
                }),
            ])
        ]);

        if (count($errors) > 0) {
            throw new Exception((string) $errors);
        }
    }
}
