<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfController extends AbstractController
{
    private function getUniqueHtmlFilename(string $originalFilename): string
    {
        $timestamp = date('Y-m-d_His');
        $info = pathinfo($originalFilename);
        return sprintf('%s_%s.%s', 
            $info['filename'],
            $timestamp,
            $info['extension']
        );
    }

    /**
     * @Route("/", name="convert_to_pdf_form", methods={"GET"})
     */
    public function showConversionForm(): Response
    {
        return $this->render('pdf/convert.html.twig');
    }

    /**
     * @Route("/wkhtmltopdf/convert-to-pdf", name="wkhtmltopdf_convert", methods={"POST"})
     */
    public function wkhtmltopdfConvert(Request $request): Response
    {
        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier');
            return $this->redirectToRoute('convert_to_pdf_form');
        }

        // Vérifier le type MIME du fichier
        $mimeType = $uploadedFile->getMimeType();
        $allowedMimeTypes = ['text/html', 'application/html', 'text/plain'];
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $this->addFlash('error', 'Type de fichier non supporté. Veuillez uploader un fichier HTML.');
            return $this->redirectToRoute('convert_to_pdf_form');
        }

        // Créer le dossier de sortie s'il n'existe pas
        $outputDir = $this->getParameter('kernel.project_dir') . '/public/wkhtmltopdf';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Déplacer le fichier HTML dans public/ avec un nom unique
        $htmlFilename = $this->getUniqueHtmlFilename($uploadedFile->getClientOriginalName());
        $htmlPath = $this->getParameter('kernel.project_dir') . '/public/' . $htmlFilename;
        $uploadedFile->move(dirname($htmlPath), basename($htmlPath));

        // Définir le chemin du PDF (on garde le même timestamp)
        $pdfFilename = pathinfo($htmlFilename, PATHINFO_FILENAME) . '.pdf';
        $outputPdfFile = $outputDir . '/' . $pdfFilename;

        try {
            // Configurer le processus wkhtmltopdf
            $process = new Process([
                'wkhtmltopdf',
                '--enable-local-file-access',
                '--encoding', 'UTF-8',
                '--load-error-handling', 'ignore',
                $htmlPath,
                $outputPdfFile
            ]);

            // Exécuter la conversion
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }

            return $this->createPdfResponse($outputPdfFile, $pdfFilename);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du PDF: ' . $e->getMessage());
            return $this->redirectToRoute('convert_to_pdf_form');
        }
    }

    /**
     * @Route("/dompdf/convert-to-pdf", name="dompdf_convert", methods={"POST"})
     */
    public function dompdfConvert(Request $request): Response
    {
        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier');
            return $this->redirectToRoute('convert_to_pdf_form');
        }

        // Vérifier le type MIME du fichier
        $mimeType = $uploadedFile->getMimeType();
        $allowedMimeTypes = ['text/html', 'application/html', 'text/plain'];
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $this->addFlash('error', 'Type de fichier non supporté. Veuillez uploader un fichier HTML.');
            return $this->redirectToRoute('convert_to_pdf_form');
        }

        // Créer le dossier de sortie s'il n'existe pas
        $outputDir = $this->getParameter('kernel.project_dir') . '/public/dompdf';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Déplacer le fichier HTML dans public/ avec un nom unique
        $htmlFilename = $this->getUniqueHtmlFilename($uploadedFile->getClientOriginalName());
        $htmlPath = $this->getParameter('kernel.project_dir') . '/public/' . $htmlFilename;
        $uploadedFile->move(dirname($htmlPath), basename($htmlPath));

        // Définir le chemin du PDF (on garde le même timestamp)
        $pdfFilename = pathinfo($htmlFilename, PATHINFO_FILENAME) . '.pdf';
        $outputPdfFile = $outputDir . '/' . $pdfFilename;

        try {
            // Configurer Dompdf
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->setChroot($this->getParameter('kernel.project_dir') . '/public');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtmlFile($htmlPath);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Sauvegarder le PDF
            file_put_contents($outputPdfFile, $dompdf->output());

            return $this->createPdfResponse($outputPdfFile, $pdfFilename);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du PDF: ' . $e->getMessage());
            return $this->redirectToRoute('convert_to_pdf_form');
        }
    }

    private function createPdfResponse(string $pdfPath, string $filename): Response
    {
        $response = new Response(file_get_contents($pdfPath));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            )
        );

        return $response;
    }
} 